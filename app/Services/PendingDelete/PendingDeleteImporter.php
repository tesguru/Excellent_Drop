<?php

namespace App\Services\PendingDelete;

use App\Models\DatasetImport;
use App\Services\ClickHouse\ClickHouseClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imports one PendingDelete.net dataset file into ClickHouse.
 *
 * A file is streamed into a staging table and merged only after the parser
 * saw the closing rows array and the declared row count matched. A failed or
 * partial download therefore never changes the current state. The merge is
 * idempotent: the current table is a ReplacingMergeTree keyed by (tld, name).
 */
class PendingDeleteImporter
{
    public const STATUS_PENDING_DELETE = 'pending_delete';

    public const STATUS_REDEMPTION = 'redemption';

    public const STATUS_DROPPED = 'dropped';

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_UNKNOWN = 'unknown';

    /** A dropped observation outranks a pending-delete one when lists overlap. */
    private const STATUS_PRIORITY = [
        self::STATUS_DROPPED => 4,
        self::STATUS_PENDING_DELETE => 3,
        self::STATUS_REDEMPTION => 2,
        self::STATUS_REGISTERED => 1,
        self::STATUS_UNKNOWN => 0,
    ];

    public function __construct(
        private readonly ClickHouseClient $clickhouse,
        private readonly PendingDeleteClient $client,
    ) {}

    /**
     * Import one file and return the distinct UTC drop dates it contained.
     *
     * @param  array<string, mixed>  $file
     * @param  array<string, mixed>  $manifest
     * @return array<int, string>
     */
    public function import(array $file, array $manifest, bool $force = false): array
    {
        $list = trim((string) ($file['list'] ?? ''));
        $tld = strtolower(trim((string) ($file['tld'] ?? '')));
        $builtUtc = trim((string) ($manifest['built_utc'] ?? ''));

        if ($list === '' || $tld === '') {
            throw new PendingDeleteException('The manifest file has no list or TLD.');
        }

        if ($builtUtc === '') {
            throw new PendingDeleteException('The manifest has no built_utc value.');
        }

        $built = $this->parseUtc($builtUtc, 'built_utc');

        if ($built === null) {
            throw new PendingDeleteException('The manifest built_utc value is invalid.');
        }

        $record = DatasetImport::firstOrNew([
            'source' => 'pendingdelete.net',
            'dataset' => $list,
            'tld' => $tld,
            'built_utc' => $builtUtc,
        ]);

        if (! $force && $record->exists && $record->status === DatasetImport::STATUS_SUCCESS) {
            return [];
        }

        $record->fill([
            'format' => (string) config('pendingdelete.format', 'json'),
            'dataset_day' => $file['day'] ?? null,
            'status' => DatasetImport::STATUS_RUNNING,
            'downloaded' => 0,
            'new_domains' => 0,
            'updated_domains' => 0,
            'status_transitions' => 0,
            'invalid_rows' => 0,
            'started_at' => now(),
            'finished_at' => null,
            'error' => null,
        ])->save();

        $importId = (string) Str::uuid();
        $observedAt = now()->utc()->format('Y-m-d H:i:s.v');
        $versionBase = (int) floor(microtime(true) * 1000) * 10;
        $batchSize = max(100, (int) config('pendingdelete.batch_size', 10000));

        $downloaded = 0;
        $invalid = 0;
        $batch = [];
        $dropDates = [];

        $flush = function () use (&$batch): void {
            if ($batch === []) {
                return;
            }

            $this->clickhouse->insertJsonEachRow(
                $this->clickhouse->database().'.pendingdelete_staging',
                $batch,
            );

            $batch = [];
        };

        try {
            $result = $this->client->stream(
                $this->client->datasetUrl($file),
                function (array $row) use (
                    &$batch,
                    &$downloaded,
                    &$invalid,
                    &$dropDates,
                    $flush,
                    $batchSize,
                    $tld,
                    $list,
                    $built,
                    $observedAt,
                    $versionBase,
                    $importId,
                ): void {
                    $downloaded++;

                    $normalized = $this->normalize($row, $tld, $list, $built, $observedAt, $versionBase, $importId);

                    if ($normalized === null) {
                        $invalid++;

                        return;
                    }

                    if ($normalized['drop_date'] !== null) {
                        $dropDates[$normalized['drop_date']] = true;
                    }

                    $batch[] = $normalized['row'];

                    if (count($batch) >= $batchSize) {
                        $flush();
                    }
                },
            );

            $flush();

            $stats = $this->merge($importId);

            $record->fill([
                'status' => DatasetImport::STATUS_SUCCESS,
                'downloaded' => $result['rows'],
                'new_domains' => $stats['new_domains'],
                'updated_domains' => $stats['updated_domains'],
                'status_transitions' => $stats['status_transitions'],
                'invalid_rows' => $invalid,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $this->discardStaging($importId);

            $record->fill([
                'status' => DatasetImport::STATUS_FAILED,
                'downloaded' => $downloaded,
                'invalid_rows' => $invalid,
                'finished_at' => now(),
                'error' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();

            throw $exception;
        }

        if ($invalid > 0) {
            Log::warning('PendingDelete import skipped invalid rows', [
                'tld' => $tld,
                'list' => $list,
                'invalid' => $invalid,
                'downloaded' => $downloaded,
            ]);
        }

        return array_keys($dropDates);
    }

    /**
     * Normalize one source row into a staging row. The identity is the lower
     * case label plus the TLD from the manifest; the domain is never stored
     * twice because the current table replaces on (tld, name).
     *
     * @param  array<string, mixed>  $row
     * @return array{row:array<string, mixed>, drop_date:?string}|null
     */
    public function normalize(
        array $row,
        string $tld,
        string $list,
        Carbon $built,
        string $observedAt,
        int $versionBase,
        string $importId = '',
    ): ?array {
        $domain = strtolower(trim((string) ($row['domain'] ?? '')));
        $domain = rtrim($domain, '.');

        if ($domain === '') {
            return null;
        }

        $dot = strrpos($domain, '.');

        if ($dot === false || $dot === 0 || $dot === strlen($domain) - 1) {
            return null;
        }

        $name = substr($domain, 0, $dot);
        $suffix = substr($domain, $dot + 1);

        if ($suffix !== $tld) {
            return null;
        }

        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $name) !== 1) {
            return null;
        }

        $status = self::statusKey((string) ($row['status'] ?? ''));
        $drop = $this->parseUtc((string) ($row['drop_utc'] ?? ''), 'drop_utc');
        $registrar = trim((string) ($row['registrar'] ?? ''));

        return [
            'drop_date' => $drop?->format('Y-m-d'),
            'row' => [
                'import_id' => $importId,
                'tld' => $tld,
                'name' => $name,
                'label_length' => strlen($name),
                'status' => $status,
                'source_list' => $list,
                'drop_utc' => $drop?->format('Y-m-d H:i:s'),
                'registrar' => mb_substr($registrar, 0, 512),
                'built_utc' => $built->format('Y-m-d H:i:s'),
                'observed_at' => $observedAt,
                'version' => $versionBase + self::STATUS_PRIORITY[$status],
            ],
        ];
    }

    /**
     * Map the source status to the three lifecycle states used by the app.
     */
    public static function statusKey(string $status): string
    {
        $status = strtolower(trim($status));

        return match (true) {
            $status === 'dropped' => self::STATUS_DROPPED,
            str_contains($status, 'pending') && str_contains($status, 'delete') => self::STATUS_PENDING_DELETE,
            str_contains($status, 'redemption') => self::STATUS_REDEMPTION,
            // The dropping-today file also carries names that are still
            // registered and have no drop time yet; they are registrations,
            // not deletions, so they never count as pending or dropped.
            $status === 'registered' => self::STATUS_REGISTERED,
            default => self::STATUS_UNKNOWN,
        };
    }

    /**
     * Copy staging into the transition log and the current state.
     *
     * @return array{merged:int, new_domains:int, updated_domains:int, status_transitions:int}
     */
    private function merge(string $importId): array
    {
        $database = $this->clickhouse->database();
        $params = ['id' => $importId];

        $source = sprintf(
            '(SELECT * FROM %s.pendingdelete_staging WHERE import_id = {id:String} '
            .'ORDER BY version DESC LIMIT 1 BY tld, name)',
            $database,
        );

        $stats = $this->clickhouse->rows(sprintf(
            'SELECT count() AS merged, '
            .'countIf(c.name IS NULL) AS new_domains, '
            .'countIf(c.name IS NOT NULL AND (c.status != s.status '
            .'OR c.label_length != s.label_length '
            .'OR c.registrar != s.registrar '
            ."OR ifNull(toString(c.drop_utc), '') != ifNull(toString(s.drop_utc), ''))) AS updated_domains, "
            .'countIf(c.name IS NOT NULL AND c.status != s.status) AS status_transitions '
            .'FROM %s AS s '
            .'LEFT JOIN ('
            .'SELECT tld, name, status, label_length, registrar, drop_utc '
            .'FROM %s.pendingdelete_names FINAL'
            .') AS c ON s.tld = c.tld AND s.name = c.name '
            .'SETTINGS join_use_nulls = 1',
            $source,
            $database,
        ), $params)[0] ?? [];

        // Transitions are written before the current row is replaced, so the
        // join still sees the status the domain had before this import.
        $this->clickhouse->execute(sprintf(
            'INSERT INTO %s.pendingdelete_status_history '
            .'(tld, name, status, source_list, drop_utc, built_utc, observed_at) '
            .'SELECT s.tld, s.name, s.status, s.source_list, s.drop_utc, s.built_utc, s.observed_at '
            .'FROM %s AS s '
            .'LEFT JOIN (SELECT tld, name, status FROM %s.pendingdelete_names FINAL) AS c '
            .'ON s.tld = c.tld AND s.name = c.name '
            .'WHERE c.name IS NULL OR c.status != s.status '
            .'SETTINGS join_use_nulls = 1',
            $database,
            $source,
            $database,
        ), $params);

        $this->clickhouse->execute(sprintf(
            'INSERT INTO %s.pendingdelete_names '
            .'(tld, name, label_length, status, source_list, drop_utc, registrar, built_utc, observed_at, version) '
            .'SELECT tld, name, label_length, status, source_list, drop_utc, registrar, built_utc, observed_at, version '
            .'FROM %s',
            $database,
            $source,
        ), $params);

        $this->discardStaging($importId);

        return [
            'merged' => (int) ($stats['merged'] ?? 0),
            'new_domains' => (int) ($stats['new_domains'] ?? 0),
            'updated_domains' => (int) ($stats['updated_domains'] ?? 0),
            'status_transitions' => (int) ($stats['status_transitions'] ?? 0),
        ];
    }

    private function discardStaging(string $importId): void
    {
        try {
            $this->clickhouse->execute(
                sprintf(
                    'ALTER TABLE %s.pendingdelete_staging DELETE WHERE import_id = {id:String}',
                    $this->clickhouse->database(),
                ),
                ['id' => $importId],
            );
        } catch (Throwable $exception) {
            // The staging table has a TTL, so a failed cleanup is harmless.
            Log::warning('PendingDelete staging cleanup failed', [
                'import_id' => $importId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function parseUtc(string $value, string $field): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            Log::warning('PendingDelete ignored an invalid '.$field, ['value' => $value]);

            return null;
        }
    }
}
