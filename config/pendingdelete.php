<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PendingDelete.net datasets
    |--------------------------------------------------------------------------
    |
    | The manifest lists every TLD/list file with its row count and day. The
    | files are rebuilt roughly every ten minutes in America/New_York. The
    | importer requests JSON with these metrics added, because the plain file
    | only contains the domain.
    |
    */

    'manifest_url' => env('PENDINGDELETE_MANIFEST_URL', 'https://pendingdelete.net/datasets/index.json'),
    'format' => env('PENDINGDELETE_FORMAT', 'json'),
    'metrics' => env('PENDINGDELETE_METRICS', 'status,length,drop,registrar'),

    /*
    |--------------------------------------------------------------------------
    | Queue and transport
    |--------------------------------------------------------------------------
    */

    'queue' => env('PENDINGDELETE_QUEUE', 'zones'),
    'connection' => env('PENDINGDELETE_CONNECTION', 'zones'),
    'timeout' => (int) env('PENDINGDELETE_TIMEOUT', 900),
    'connect_timeout' => (int) env('PENDINGDELETE_CONNECT_TIMEOUT', 10),
    'manifest_timeout' => (int) env('PENDINGDELETE_MANIFEST_TIMEOUT', 30),
    'batch_size' => (int) env('PENDINGDELETE_BATCH_SIZE', 10000),
    'user_agent' => env('PENDINGDELETE_USER_AGENT', 'DropWatch/1.0 (+https://excellentdrop.lookupfy.com)'),

    /*
    |--------------------------------------------------------------------------
    | Lists and refresh cadence
    |--------------------------------------------------------------------------
    |
    | Dropping today is the actionable list and is refreshed every ten
    | minutes. The pending backlog and yesterday's drops change much more
    | slowly, so they refresh less often and keep the single worker free.
    |
    */

    'lists' => ['dropping-today', 'pending', 'dropped-yesterday'],

    'schedule' => [
        'dropping-today' => env('PENDINGDELETE_DROPPING_CRON', '*/10 * * * *'),
        'pending' => env('PENDINGDELETE_PENDING_CRON', '5 * * * *'),
        'dropped-yesterday' => env('PENDINGDELETE_DROPPED_CRON', '15 */6 * * *'),
    ],

];
