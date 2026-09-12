<?php

namespace App\Console\Commands;

use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Pipeline\Stage;
use App\Models\Conversion;
use Illuminate\Console\Command;

/**
 * Puts failed conversions back in the queue.
 *
 * The pipeline stops after converter.failure.max_attempts so that one broken document cannot spin
 * for ever, and it deliberately leaves those rows alone afterwards. When the cause was the machine
 * rather than the document - the FTP site down, a full staging drive, the archive not answering -
 * this is how they are queued again, instead of writing SQL against the panel's database.
 *
 * Only the panel's own rows are touched. A content that ran out of attempts was already handed back
 * to the archive by the pipeline, so there is nothing to undo there; its pages and uploaded images
 * were removed when the attempt failed.
 */
class RetryConversions extends Command
{
    /**
     * @var string
     */
    protected $signature = 'converters:retry
        {--content=* : content ids to put back}
        {--stage= : only failures at this step}
        {--all : every failed conversion}
        {--limit=5000 : most conversions to put back in one run}';

    /**
     * @var string
     */
    protected $description = 'Put failed conversions back in the queue';

    public function handle(): int
    {
        $failed = Conversion::query()->where('status', ConversionStatus::Failed);

        if (! $this->option('all') && $this->option('content') === [] && $this->option('stage') === null) {
            $this->components->error('Nothing selected. Pass --all, --content=<id> (repeatable), or --stage=<step>.');
            $this->reportWhatFailed();

            return self::FAILURE;
        }

        if ($this->option('content') !== []) {
            $failed->whereIn('content_id', array_map('strtolower', (array) $this->option('content')));
        }

        if (($stage = $this->option('stage')) !== null) {
            if (Stage::tryFrom((string) $stage) === null) {
                $this->components->error(sprintf(
                    'There is no step "%s". The steps are: %s.',
                    $stage,
                    implode(', ', array_column(Stage::cases(), 'value')),
                ));

                return self::FAILURE;
            }

            $failed->where('failure_stage', $stage);
        }

        // Taken in one pass over the oldest failures, so a run that hits the limit can simply be run
        // again; the attempt count goes back to zero because the fault was not the document's.
        $ids = $failed->orderBy('finished_at')->limit((int) $this->option('limit'))->pluck('id');

        if ($ids->isEmpty()) {
            $this->components->info('No failed conversion matches.');

            return self::SUCCESS;
        }

        $put = Conversion::query()->whereIn('id', $ids)->update([
            'status' => ConversionStatus::Pending->value,
            'attempts' => 0,
            'failure_stage' => null,
            'failure_reason' => null,
            'worker' => null,
            'claimed_at' => null,
            'heartbeat_at' => null,
            'finished_at' => null,
            'updated_at' => now(),
        ]);

        $this->components->info("Put {$put} conversion(s) back in the queue.");
        $this->reportWhatFailed();

        return self::SUCCESS;
    }

    /**
     * What is still failed, by step, so the operator can see what is worth retrying and what is a
     * verdict about the documents themselves.
     */
    private function reportWhatFailed(): void
    {
        $byStage = Conversion::query()
            ->where('status', ConversionStatus::Failed)
            ->selectRaw('failure_stage, count(*) as total')
            ->groupBy('failure_stage')
            ->orderByDesc('total')
            ->pluck('total', 'failure_stage');

        if ($byStage->isEmpty()) {
            $this->line('  Nothing is failed.');

            return;
        }

        $this->line('  Still failed:');

        foreach ($byStage as $stage => $total) {
            $this->line(sprintf('    %-10s %6d', $stage ?? 'unknown', $total));
        }
    }
}
