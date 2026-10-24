<?php

namespace App\Jobs;

use App\Services\TracksJobRun;
use App\Services\Zone\ZoneExtractor;
use App\Services\ZonePaths;
use App\Services\ZoneRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step 2 of the chain: stream one zone file and produce the sorted, unique
 * list of second level labels.
 */
class ExtractNames implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksJobRun;

    public int $timeout = 0;

    public bool $failOnTimeout = true;

    public int $tries = 1;

    public function __construct(
        public readonly string $runId,
        public readonly string $tld,
    ) {
        $this->onQueue((string) config('zones.queue'));
        $this->onConnection('zones');
    }

    public function runIdentifier(): string
    {
        return $this->runId;
    }

    public function handle(ZoneExtractor $extractor, ZonePaths $paths, ZoneRegistry $registry): void
    {
        $this->beginRun('extract', $this->tld);

        try {
            $gzPath = $paths->zoneFile($this->tld);

            if (! is_file($gzPath)) {
                $this->skipRun('No zone file downloaded for '.$this->tld);

                return;
            }

            $layout = $extractor->verifyLayout($gzPath, $this->tld);

            if (! $layout['ok']) {
                $registry->mark($this->tld, ['status' => 'failed', 'message' => 'Unexpected zone file layout']);

                $this->failRun(new \RuntimeException(
                    'Unexpected zone file layout for '.$this->tld.'. Expected owner, TTL, class, type and rdata columns.',
                ));

                return;
            }

            Log::info('Zone layout verified', [
                'tld' => $this->tld,
                'columns' => $layout['columns'],
                'sample' => $layout['sample'],
            ]);

            $outPath = $paths->namesFile($this->tld);

            $result = $extractor->extract($gzPath, $this->tld, $outPath);

            // The raw zone file is only needed for the extraction. Releasing it
            // here keeps a full daily run inside the disk budget; cleanup would
            // otherwise hold every zone file until the end of the chain.
            if (! (bool) config('zones.keep_raw', false)) {
                @unlink($gzPath);
            }

            $this->finishRun($result['rows'], sprintf(
                'Extracted %s names (%s bytes) from %s',
                number_format($result['rows']),
                number_format($result['bytes']),
                basename($gzPath),
            ));

            LoadSnapshot::dispatch($this->runId, $this->tld)->onQueue((string) config('zones.queue'));
        } catch (Throwable $exception) {
            $registry->mark($this->tld, ['status' => 'failed', 'message' => $exception->getMessage()]);

            $this->failRun($exception);

            throw $exception;
        }
    }
}
