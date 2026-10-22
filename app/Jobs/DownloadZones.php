<?php

namespace App\Jobs;

use App\Models\JobRun;
use App\Services\Czds\CzdsClient;
use App\Services\Czds\CzdsException;
use App\Services\JobRunTracker;
use App\Services\TracksJobRun;
use App\Services\ZoneRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Step 1 of the chain: log in to CZDS, read the zone list and download
 * every zone the account has access to.
 */
class DownloadZones implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksJobRun;

    /** The .com zone is several gigabytes, so the job may run for a long time. */
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

    public function handle(CzdsClient $czds, ZoneRegistry $registry): void
    {
        $zonesDir = (string) config('zones.dir');

        if (! is_dir($zonesDir)) {
            mkdir($zonesDir, 0755, true);
        }

        try {
            $token = $czds->authenticate();
            $links = $czds->zoneLinks($token);

            $registry->syncApproved($links);

            Log::info('CZDS zone list', ['zones' => array_keys($links)]);

            $downloaded = [];
            $skipped = [];
            $available = [];

            foreach ($links as $tld => $url) {
                try {
                    $outcome = $this->downloadZone($czds, $token, $tld, $url, $zonesDir);

                    if ($outcome['status'] === 'downloaded') {
                        $downloaded[] = $tld;
                    } else {
                        $skipped[] = $tld.': '.$outcome['message'];
                    }

                    if (is_file($zonesDir.'/'.$tld.'.zone.gz')) {
                        $available[] = $tld;
                    }
                } catch (CzdsException $exception) {
                    if (! $exception->fatal) {
                        // 403: the zone is not approved for this account.
                        $skipped[] = $tld.': '.$exception->getMessage();
                        Log::warning('Zone skipped', ['tld' => $tld, 'reason' => $exception->getMessage()]);

                        continue;
                    }

                    throw $exception;
                }
            }

            $message = sprintf('Downloaded %d zone(s)', count($downloaded));

            if ($skipped !== []) {
                $message .= ', skipped: '.implode('; ', $skipped);
            }

            Log::info('Zone download finished', ['downloaded' => $downloaded, 'skipped' => $skipped]);

            $this->updateSummary($downloaded, $skipped);
            $this->dispatchExtractions($available);
        } catch (Throwable $exception) {
            $this->failSummary($exception);

            if ($exception instanceof CzdsException && $exception->statusCode === 409) {
                $this->alertTermsChanged($exception);
            }

            throw $exception;
        }
    }

    /**
     * @return array{status:string, message:string}
     */
    private function downloadZone(CzdsClient $czds, string $token, string $tld, string $url, string $zonesDir): array
    {
        $row = $this->newRun('download', $tld);

        try {
            $head = $this->freshHead($czds, $token, $tld, $url, $row);

            if ($head['status'] === 403) {
                $this->skipRow($row, 'Access to this zone is not approved (403).');

                return ['status' => 'skipped', 'message' => 'not approved'];
            }

            if ($head['status'] !== 200) {
                $this->skipRow($row, 'HEAD request returned '.$head['status']);

                return ['status' => 'skipped', 'message' => 'HEAD status '.$head['status']];
            }

            $modified = $this->parseLastModified($head['last_modified'] ?? null);

            // The new file has not been published yet. Do not download the
            // stale one: the step fails so the run can be retried later.
            if ($head['stale']) {
                $message = $this->staleMessage($tld, $modified);

                $this->markStale($tld, $modified, $message);
                $this->updateRow($row, ['message' => $message]);

                throw new CzdsException($message);
            }

            $total = (int) $head['length'];
            $target = $zonesDir.'/'.$tld.'.zone.gz';

            $this->updateRow($row, ['bytes_total' => $total]);

            if ($this->alreadyDownloadedToday($target)) {
                $this->skipRow($row, 'Already downloaded today.');
                $registry = app(ZoneRegistry::class);
                $registry->mark($tld, [
                    'status' => 'downloaded',
                    'last_download_at' => now(),
                    'last_modified' => $modified,
                    'file_size' => (int) (filesize($target) ?: 0),
                    'file_path' => $target,
                ]);

                return ['status' => 'skipped', 'message' => 'already downloaded'];
            }

            $free = @disk_free_space($zonesDir);

            if ($total > 0 && $free !== false && $free < $total * 1.2) {
                $message = sprintf('Not enough disk space: %.1f GB free, %.1f GB needed', $free / 1073741824, ($total * 1.2) / 1073741824);
                $this->skipRow($row, $message);

                return ['status' => 'skipped', 'message' => $message];
            }

            $lastUpdate = 0.0;

            $czds->download($url, $token, $target, function (int $bytes, int $size) use ($row, &$lastUpdate, $total): void {
                $now = microtime(true);

                if ($now - $lastUpdate < 1.0) {
                    return;
                }

                $lastUpdate = $now;

                $this->updateRow($row, [
                    'bytes_done' => $bytes,
                    'bytes_total' => $size > 0 ? $size : $total,
                ]);
            });

            $size = (int) (filesize($target) ?: 0);

            app(ZoneRegistry::class)->mark($tld, [
                'status' => 'downloaded',
                'last_download_at' => now(),
                'last_modified' => $modified,
                'file_size' => $size,
                'file_path' => $target,
            ]);

            $this->succeedRow($row, null, sprintf('Downloaded %s bytes', number_format($size)));

            return ['status' => 'downloaded', 'message' => 'downloaded'];
        } catch (CzdsException $exception) {
            if ($exception->fatal) {
                $this->failRow($row, $exception);

                throw $exception;
            }

            $this->skipRow($row, $exception->getMessage());

            return ['status' => 'skipped', 'message' => $exception->getMessage()];
        } catch (Throwable $exception) {
            $this->failRow($row, $exception);

            throw $exception;
        }
    }

    /**
     * HEAD the zone and wait until the published file is fresh enough.
     *
     * ICANN publishes the zone files after 06:00 UTC. If Last-Modified is
     * older than the threshold the previous day's file is still online, so
     * we wait and check again instead of saving a stale file.
     *
     * @return array{status:int, length:int, last_modified:?string, stale:bool}
     */
    private function freshHead(CzdsClient $czds, string $token, string $tld, string $url, JobRun $row): array
    {
        $staleHours = max(1, (int) config('zones.stale_after_hours', 20));
        $retryMinutes = max(1, (int) config('zones.stale_retry_minutes', 30));
        $maxRetries = max(0, (int) config('zones.stale_max_retries', 4));

        for ($attempt = 0; ; $attempt++) {
            $head = $czds->head($url, $token);

            if ($head['status'] !== 200) {
                return $head + ['stale' => false];
            }

            $modified = $this->parseLastModified($head['last_modified'] ?? null);

            if ($modified === null || $modified->gte(now()->subHours($staleHours))) {
                return $head + ['stale' => false];
            }

            if ($attempt >= $maxRetries) {
                return $head + ['stale' => true];
            }

            $message = sprintf(
                'Zone file was last modified at %s, older than %d hours; retrying in %d minute(s) (%d/%d).',
                $modified->toIso8601String(),
                $staleHours,
                $retryMinutes,
                $attempt + 1,
                $maxRetries,
            );

            Log::info('Zone file not published yet', [
                'tld' => $tld,
                'last_modified' => $head['last_modified'],
                'attempt' => $attempt + 1,
                'max_retries' => $maxRetries,
            ]);

            $this->updateRow($row, ['message' => $message]);

            Sleep::for($retryMinutes)->minutes();
        }
    }

    private function parseLastModified(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            // HTTP dates are GMT. Normalise to the app timezone before we store
            // or display the value: Laravel serialises datetimes without an
            // offset, so a UTC wall clock would drift by the timezone delta.
            return Carbon::parse($value)->setTimezone((string) config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    private function staleMessage(string $tld, ?Carbon $modified): string
    {
        return sprintf(
            'The %s zone file is stale: Last-Modified %s is older than %d hours, so the new file is not published yet. Gave up after %d retries of %d minute(s).',
            $tld,
            $modified?->toIso8601String() ?? 'unknown',
            (int) config('zones.stale_after_hours', 20),
            (int) config('zones.stale_max_retries', 4),
            (int) config('zones.stale_retry_minutes', 30),
        );
    }

    private function markStale(string $tld, ?Carbon $modified, string $message): void
    {
        app(ZoneRegistry::class)->mark($tld, [
            'status' => 'stale',
            'last_modified' => $modified,
            'message' => $message,
        ]);
    }

    /**
     * Step 2 of the chain starts once the zone files are on disk.
     *
     * @param  array<int, string>  $tlds
     */
    private function dispatchExtractions(array $tlds): void
    {
        foreach ($tlds as $tld) {
            ExtractNames::dispatch($this->runId, $tld)->onQueue((string) config('zones.queue'));
        }
    }

    private function alreadyDownloadedToday(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $modified = @filemtime($path);

        if ($modified === false) {
            return false;
        }

        return date('Y-m-d', $modified) === now()->format('Y-m-d');
    }

    /**
     * @param  array<int, string>  $downloaded
     * @param  array<int, string>  $skipped
     */
    private function updateSummary(array $downloaded, array $skipped): void
    {
        $row = $this->newRun('download', null);

        app(JobRunTracker::class)->succeed(
            $row,
            count($downloaded),
            sprintf('Downloaded %d zone(s), skipped %d', count($downloaded), count($skipped)),
        );
    }

    private function failSummary(Throwable $exception): void
    {
        $row = $this->newRun('download', null);

        app(JobRunTracker::class)->fail($row, $exception->getMessage());
    }

    private function alertTermsChanged(CzdsException $exception): void
    {
        try {
            Mail::raw(
                "ICANN requires the new CZDS Terms & Conditions to be accepted.\n\n"
                ."Log in at https://czds.icann.org and accept the new terms, then re-run the pipeline.\n\n"
                .'Error: '.$exception->getMessage(),
                function ($message): void {
                    $message->to(config('mail.from.address'))
                        ->subject('DropWatch: CZDS Terms & Conditions need to be accepted');
                },
            );
        } catch (Throwable $mailException) {
            Log::error('Unable to send the CZDS terms alert', ['error' => $mailException->getMessage()]);
        }
    }
}
