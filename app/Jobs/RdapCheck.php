<?php

namespace App\Jobs;

use App\Models\RdapStatus;
use App\Services\ClickHouse\ClickHouseClient;
use App\Services\Rdap\RdapClient;
use App\Services\TracksJobRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step 6 of the chain: check the RDAP status of the candidates.
 *
 * A name is checked weekly, and daily once it reached the pending delete
 * stage. Results are cached in rdap_status.
 */
class RdapCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksJobRun;

    public int $timeout = 0;

    public bool $failOnTimeout = true;

    public int $tries = 1;

    public function __construct(public readonly string $runId)
    {
        $this->onQueue((string) config('zones.queue'));
    }

    public function runIdentifier(): string
    {
        return $this->runId;
    }

    public function handle(ClickHouseClient $clickhouse, RdapClient $rdap): void
    {
        $this->beginRun('rdap', null);

        try {
            $candidates = $this->candidates($clickhouse);

            if ($candidates === []) {
                $this->skipRun('No candidates to check.');

                Cleanup::dispatch($this->runId)->onQueue((string) config('zones.queue'));

                return;
            }

            $removalDates = $this->removalDates($clickhouse, array_keys($candidates));

            $checked = 0;
            $skipped = 0;
            $restored = 0;
            $messages = [];

            foreach ($candidates as $name => $eventDate) {
                $domain = $name.'.com';

                $status = RdapStatus::firstOrNew(['domain' => $domain]);

                if (! $this->isDue($status)) {
                    $skipped++;

                    continue;
                }

                $result = $rdap->lookup($domain);

                if (! $result['found']) {
                    $messages[] = $domain.': '.($result['error'] ?? 'lookup failed');

                    continue;
                }

                $checked++;

                $rdapStatus = strtolower((string) $result['status']);

                $isRestored = in_array($rdapStatus, ['active', 'hold'], true)
                    || in_array('hold', array_map('strtolower', $result['statuses']), true);

                $predictedDrop = $status->predicted_drop_date;

                if ($result['pending_delete_date'] !== null) {
                    $predictedDrop = Carbon::parse($result['pending_delete_date'])
                        ->addDays((int) config('zones.drop.pending_delete_days', 5))
                        ->format('Y-m-d');
                } elseif ($predictedDrop === null && isset($removalDates[$name])) {
                    $predictedDrop = Carbon::parse($removalDates[$name])
                        ->addDays((int) config('zones.drop.redemption_days', 30) + (int) config('zones.drop.pending_delete_days', 5))
                        ->format('Y-m-d');
                }

                $status->fill([
                    'domain' => $domain,
                    'status' => $result['status'],
                    'expiry' => $result['expiry'],
                    'pending_delete_date' => $result['pending_delete_date'],
                    'predicted_drop_date' => $predictedDrop,
                    'last_checked' => now(),
                    'restored' => $isRestored,
                ]);

                $status->save();

                if ($isRestored) {
                    $restored++;
                }
            }

            $message = sprintf('Checked %d candidate(s), skipped %d, restored %d', $checked, $skipped, $restored);

            if ($messages !== []) {
                $message .= ' | '.implode('; ', array_slice($messages, 0, 5));
            }

            Log::info('RDAP check finished', ['checked' => $checked, 'skipped' => $skipped, 'restored' => $restored]);

            $this->finishRun($checked, $message);
        } catch (Throwable $exception) {
            $this->failRun($exception);

            throw $exception;
        }

        Cleanup::dispatch($this->runId)->onQueue((string) config('zones.queue'));
    }

    private function isDue(RdapStatus $status): bool
    {
        if ($status->last_checked === null) {
            return true;
        }

        $daily = strtolower((string) $status->status) === 'pending delete';

        $interval = $daily ? 1 : 7;

        return $status->last_checked->lt(now()->subDays($interval));
    }

    /**
     * @return array<string, string> name => event date
     */
    private function candidates(ClickHouseClient $clickhouse): array
    {
        $from = now()->subDays((int) config('zones.retain_days', 45))->format('Y-m-d');

        $rows = $clickhouse->rows(
            sprintf(
                'SELECT name, event_date FROM %s.com_candidates WHERE event_date >= {from:Date} ORDER BY score DESC',
                $clickhouse->database(),
            ),
            ['from' => $from],
        );

        $candidates = [];

        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $candidates[$name] = (string) ($row['event_date'] ?? '');
        }

        return $candidates;
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, string> name => removal date
     */
    private function removalDates(ClickHouseClient $clickhouse, array $names): array
    {
        if ($names === []) {
            return [];
        }

        $rows = $clickhouse->rows(
            sprintf(
                'SELECT name, min(event_date) AS event_date FROM %s.zone_events '
                .'WHERE tld = {tld:String} AND event = {event:String} AND name IN ({names:Array(String)}) '
                .'GROUP BY name',
                $clickhouse->database(),
            ),
            ['tld' => 'com', 'event' => 'removed', 'names' => array_values($names)],
        );

        $dates = [];

        foreach ($rows as $row) {
            $dates[(string) ($row['name'] ?? '')] = (string) ($row['event_date'] ?? '');
        }

        return $dates;
    }
}
