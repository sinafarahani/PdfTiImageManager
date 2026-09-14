<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A source PDF that converters:purge-sources destroyed, and where it used to be.
 *
 * Written before anything is deleted, and kept afterwards. Once the file is gone from the file store
 * and its MVDContent row is gone from the archive, this row is the only thing anywhere that says the
 * original existed, what it was called and which folder it was in. It cannot bring it back.
 *
 * @property int $id
 * @property string $content_id
 * @property string $mvd_id
 * @property int|null $conversion_id
 * @property string $remote_path
 * @property string|null $original_name
 * @property string|null $create_date_time
 * @property bool $file_deleted
 * @property bool $row_deleted
 * @property int|null $bytes
 */
class PurgedSource extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'content_id',
        'mvd_id',
        'conversion_id',
        'remote_path',
        'original_name',
        'create_date_time',
        'file_deleted',
        'row_deleted',
        'bytes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'file_deleted' => false,
        'row_deleted' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_deleted' => 'boolean',
            'row_deleted' => 'boolean',
            'bytes' => 'integer',
        ];
    }
}
