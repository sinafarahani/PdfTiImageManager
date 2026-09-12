<?php

namespace App\Console\Commands;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Pipeline\Stage;
use App\Models\Conversion;
use Illuminate\Console\Command;
use Throwable;

/**
 * Puts failed conversions back in the queue.
 *
 * The pipeline stops after converter.failure.max_attempts so that one broken document cannot spin
 * for ever, and it deliberately leaves those rows alone afterwards. When the cause was the machine
 * rather than the document - the FTP site down, a full staging drive, the archive not answering -
 * this is how they are queued again, instead of writing SQL against the panel's database.
 *
 * It also frees the content in the archive, because the panel's row is only half of the state: a
 * content this pipeline gave up on carries the archive's failure marker, and one the retired pipeline
 * died holding still carries that worker's GUID. Neither can be reserved again, so a retry that only
 * touched the panel would spend the content's attempts and land it straight back in failed.
 *
 * The pages and images of a failed attempt were already removed when it failed, so there is nothing
 * else to undo.
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

    public function handle(ArchiveGateway $archive): int
    {
        $failed = Conversion::query();

        if (! $this->option('all') && $this->option('content') === [] && $this->option('stage') === null) {
            $this->components->error('Nothing selected. Pass --all, --content=<id> (repeatable), or --stage=<step>.');
            $this->reportWhatFailed();

            return self::FAILURE;
        }

        if ($this->option('content') !== []) {
            $failed->whereIn('content_id', array_map('strtolower', (array) $this->option('content')));
        }

        $step = null;

        if (($stage = $this->option('stage')) !== null) {
            if (($step = Stage::tryFrom((string) $stage)) === null) {
                $this->components->error(sprintf(
                    'There is no step "%s". The steps are: %s.',
                    $stage,
                    implode(', ', array_column(Stage::cases(), 'value')),
                ));

                return self::FAILURE;
            }

            $failed->where('failure_stage', $stage);
        }

        // Contents of a profile that is not converted were cancelled rather than failed, and asking
        // for that step by name is the only way to reach them. --all deliberately does not: a retry
        // after an FTP outage would otherwise put a few hundred thousand of them back in the queue.
        $failed->where('status', $step === Stage::Skipped ? ConversionStatus::Cancelled : ConversionStatus::Failed);

        // Taken in one pass over the oldest failures, so a run that hits the limit can simply be run
        // again; the attempt count goes back to zero because the fault was not the document's.
        $matched = $failed->orderBy('finished_at')->limit((int) $this->option('limit'))->get(['id', 'content_id']);
        $ids = $matched->pluck('id');

        if ($ids->isEmpty()) {
            $this->components->info('No failed conversion matches.');

            return self::SUCCESS;
        }

        $this->freeInTheArchive($archive, $matched->pluck('content_id')->all());

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
     * Frees these contents in the archive, or says why it could not.
     *
     * Putting the panel's own row back is only half of it: the archive holds the lock. A content this
     * pipeline gave up on carries the failure marker, and one the retired pipeline died on still
     * carries that worker's GUID - either way reserve() can never win it again, and a retry without
     * this would quietly spend the content's three attempts and put it straight back where it was.
     *
     * @param  list<string>  $contentIds
     */
    private function freeInTheArchive(ArchiveGateway $archive, array $contentIds): void
    {
        try {
            $freed = $archive->freeReservation($contentIds);
        } catch (Throwable $exception) {
            report($exception);

            $this->components->warn('The archive could not be reached, so the contents are queued but still locked there: '.$exception->getMessage());
            $this->line('  They will fail at "taking the content" until this command is run again with the archive reachable and CONVERTER_WRITE_MODE=on.');

            return;
        }

        $this->components->twoColumnDetail('freed in the archive', sprintf('%d of %d', $freed, count($contentIds)));

        if ($freed < count($contentIds)) {
            $this->line('  The rest are contents the archive already counts as converted, or that have page images: those are left alone.');
        }
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
