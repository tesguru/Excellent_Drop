<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One download of one PendingDelete.net dataset file.
 *
 * The unique key is the dataset version: source + list + TLD + built_utc.
 * Re-running a manifest that was already imported successfully is a no-op
 * unless --force is used.
 */
class DatasetImport extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'source',
        'dataset',
        'tld',
        'format',
        'dataset_day',
        'built_utc',
        'status',
        'downloaded',
        'new_domains',
        'updated_domains',
        'status_transitions',
        'invalid_rows',
        'started_at',
        'finished_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'dataset_day' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'downloaded' => 'integer',
            'new_domains' => 'integer',
            'updated_domains' => 'integer',
            'status_transitions' => 'integer',
            'invalid_rows' => 'integer',
        ];
    }
}
