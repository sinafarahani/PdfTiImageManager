<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A source PDF row that this conversion hid once its pages existed.
 *
 * The archive's own record of a converted source is MVDContent.Deleted = 1, and that flag is not
 * enough to act on: a content can carry PDF rows somebody deleted in the viewer years ago, which were
 * never rendered and can be put back with one update. Only this ledger says which rows the pipeline
 * itself hid, and it is what converters:purge-sources is allowed to destroy.
 *
 * @property int $id
 * @property int $conversion_id
 * @property string $content_id
 * @property string $mvd_id
 */
class ConversionSource extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversion_id',
        'content_id',
        'mvd_id',
    ];

    /**
     * @return BelongsTo<Conversion, $this>
     */
    public function conversion(): BelongsTo
    {
        return $this->belongsTo(Conversion::class);
    }
}
