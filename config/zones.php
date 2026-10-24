<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CZDS (ICANN Central Zone Data Service)
    |--------------------------------------------------------------------------
    |
    | Zone data is licensed for the account holder's own use only. Never
    | expose it publicly and never redistribute it.
    |
    */

    'czds' => [
        'username' => env('CZDS_USERNAME'),
        'password' => env('CZDS_PASSWORD'),
        'user_agent' => env('CZDS_USER_AGENT', 'DropWatch/1.0'),
        'auth_url' => env('CZDS_AUTH_URL', 'https://account-api.icann.org/api/authenticate'),
        'links_url' => env('CZDS_LINKS_URL', 'https://czds-api.icann.org/czds/downloads/links'),
        'timeout' => (int) env('CZDS_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | ClickHouse
    |--------------------------------------------------------------------------
    |
    | The heavy lifting is done through the clickhouse-client binary so that
    | PHP never has to hold a zone file (or a bulk result set) in memory.
    |
    */

    'clickhouse' => [
        'host' => env('CLICKHOUSE_HOST', '127.0.0.1'),
        'port' => (int) env('CLICKHOUSE_PORT', 9000),
        'http_port' => (int) env('CLICKHOUSE_HTTP_PORT', 8123),
        'database' => env('CLICKHOUSE_DATABASE', 'zones'),
        'client_bin' => env('CLICKHOUSE_CLIENT_BIN', 'clickhouse-client'),
        'user' => env('CLICKHOUSE_USER', 'default'),
        'password' => env('CLICKHOUSE_PASSWORD', ''),
        'timeout' => (int) env('CLICKHOUSE_TIMEOUT', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Zone processing
    |--------------------------------------------------------------------------
    */

    'dir' => env('ZONES_DIR', storage_path('app/zones')),
    'sort_tmp_dir' => env('SORT_TMP_DIR', '/var/tmp/dropwatch-sort'),
    'cat_binary' => env('ZONE_CAT_BINARY', 'gzip -dc'),
    'keep_raw' => (bool) env('KEEP_RAW', false),

    // Number of daily snapshots kept in ClickHouse, today included. Two means
    // today and yesterday: enough for the next diff and nothing to search.
    'retain_days' => (int) env('ZONE_RETAIN_DAYS', 2),

    // ICANN publishes the zone files after 06:00 UTC. A Last-Modified header
    // older than this means the new file is not out yet; the download step
    // waits and retries instead of saving a stale file.
    'stale_after_hours' => (int) env('ZONE_STALE_AFTER_HOURS', 20),
    'stale_retry_minutes' => (int) env('ZONE_STALE_RETRY_MINUTES', 30),
    'stale_max_retries' => (int) env('ZONE_STALE_MAX_RETRIES', 4),

    'queue' => env('ZONES_QUEUE', 'zones'),
    'heartbeat_queue' => env('ZONE_HEARTBEAT_QUEUE', 'heartbeat'),
    'schedule' => env('ZONES_SCHEDULE', '0 8 * * *'),
    'heartbeat_cache_key' => 'zones:worker_heartbeat',
    'heartbeat_stale_seconds' => (int) env('ZONES_HEARTBEAT_STALE_SECONDS', 180),

    /*
    |--------------------------------------------------------------------------
    | Redemption / drop prediction
    |--------------------------------------------------------------------------
    |
    | A name that leaves the zone is normally in redemption (~30 days) and
    | then pending delete (5 days) before it is actually deleted. A removal
    | is never reported as "available".
    |
    */

    'drop' => [
        'redemption_days' => (int) env('ZONE_REDEMPTION_DAYS', 30),
        'pending_delete_days' => (int) env('ZONE_PENDING_DELETE_DAYS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | External domain lookup
    |--------------------------------------------------------------------------
    |
    | DropWatch never fetches RDAP itself. These links are rendered for a
    | human to open in a new tab; {domain} is replaced with the encoded name.
    |
    */

    'lookup_link_template' => env('LOOKUP_LINK_TEMPLATE', 'https://rdap.org/domain/{domain}'),

    /*
    |--------------------------------------------------------------------------
    | Weaker version search
    |--------------------------------------------------------------------------
    */

    'weaker' => [
        'max_per_rule' => (int) env('WEAKER_MAX_PER_RULE', 50),
        'phase2_enabled' => (bool) env('WEAKER_PHASE2_ENABLED', false),
        'chunk_size' => (int) env('WEAKER_CHUNK_SIZE', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Zone access expiry
    |--------------------------------------------------------------------------
    |
    | ICANN grants access per zone for a limited period. The dates below are
    | refreshed from the CZDS account API when it exposes them, and act as a
    | fallback so the dashboard can warn 14 days before access ends.
    |
    */

    'zone_access' => [
        'warning_days' => (int) env('ZONE_ACCESS_WARNING_DAYS', 14),
        'expiry' => [
            'biz' => env('ZONE_ACCESS_EXPIRY_BIZ', '2026-11-08'),
        ],
    ],

];
