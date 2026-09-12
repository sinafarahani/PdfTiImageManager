<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One page row this pipeline wrote into the archive, and where its image went. It is written before
 * the work is done, never after: if the worker dies mid-content, this ledger is the only record of
 * which MVDContent rows and which FTP files exist and have to be removed before the content is
 * converted again. The old pipeline kept nothing of the sort, which is why rebuilding an interrupted
 * conversion used to be a manual hunt.
 *
 * @property int $id
 * @property int $conversion_id
 * @property int $seq
 * @property string|null $mvd_id
 * @property string|null $remote_path
 * @property int|null $bytes
 * @property bool $uploaded
 */
class ConversionPage extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversion_id',
        'seq',
        'mvd_id',
        'remote_path',
        'bytes',
        'uploaded',
    ];

    /**
     * The column defaults, repeated here so that a page just recorded reads the same in memory as it
     * does after a reload: the cleanup asks a freshly created row whether it was uploaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'uploaded' => false,
    ];

    /**
     * @return BelongsTo<Conversion, $this>
     */
    public function conversion(): BelongsTo
    {
        return $this->belongsTo(Conversion::class);
    }

    /**
     * @param  Builder<ConversionPage>  $query
     */
    public function scopeUploaded(Builder $query): void
    {
        $query->where('uploaded', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'bytes' => 'integer',
            'uploaded' => 'boolean',
        ];
    }
}
