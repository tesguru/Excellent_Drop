<?php

namespace Tests\Feature;

use App\Jobs\DownloadZones;
use App\Models\JobRun;
use App\Models\ZoneTld;
use App\Services\Czds\CzdsClient;
use App\Services\Czds\CzdsException;
use App\Services\ZoneRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class DownloadZonesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }

    public function test_a_stale_zone_file_is_not_downloaded_and_fails_the_step(): void
    {
        Sleep::fake();
        Queue::fake();

        config(['zones.dir' => $this->tempDir()]);

        $client = new class extends CzdsClient
        {
            public int $headCalls = 0;

            public int $downloads = 0;

            public function __construct()
            {
                parent::__construct([]);
            }

            public function authenticate(): string
            {
                return 'token';
            }

            public function zoneLinks(string $token): array
            {
                return ['com' => 'https://czds-api.icann.org/czds/downloads/com.zone'];
            }

            public function head(string $url, string $token): array
            {
                $this->headCalls++;

                return [
                    'status' => 200,
                    'length' => 1024,
                    'last_modified' => now()->subHours(30)->utc()->toRfc7231String(),
                ];
            }

            public function download(string $url, string $token, string $targetPath, callable $onProgress): array
            {
                $this->downloads++;

                return ['status' => 200, 'bytes' => 0, 'path' => $targetPath];
            }
        };

        $job = new DownloadZones('11111111-1111-4111-8111-111111111111');

        try {
            $job->handle($client, app(ZoneRegistry::class));

            $this->fail('The download step should have failed for a stale zone file.');
        } catch (CzdsException $exception) {
            $this->assertStringContainsString('stale', $exception->getMessage());
            $this->assertStringContainsString('Last-Modified', $exception->getMessage());
        }

        // The file is never downloaded and the stale HEAD is retried four times.
        $this->assertSame(0, $client->downloads);
        $this->assertSame(5, $client->headCalls);
        Sleep::assertSleptTimes(4);

        $this->assertFileDoesNotExist(config('zones.dir').'/com.zone.gz');

        $zone = ZoneTld::where('tld', 'com')->firstOrFail();
        $this->assertSame('stale', $zone->status);
        $this->assertNotNull($zone->last_modified);
        $this->assertStringContainsString('stale', (string) $zone->message);

        $row = JobRun::where('step', 'download')->where('tld', 'com')->latest('id')->firstOrFail();
        $this->assertSame(JobRun::STATUS_FAILED, $row->status);
        $this->assertStringContainsString('stale', (string) $row->message);
        $this->assertStringContainsString('stale', (string) $row->error);
    }

    public function test_a_fresh_zone_records_its_last_modified_date(): void
    {
        Sleep::fake();
        Queue::fake();

        config(['zones.dir' => $this->tempDir()]);

        $modified = now()->subHour();

        $client = new class($modified->copy()->utc()->toRfc7231String()) extends CzdsClient
        {
            public function __construct(private readonly string $lastModified)
            {
                parent::__construct([]);
            }

            public function authenticate(): string
            {
                return 'token';
            }

            public function zoneLinks(string $token): array
            {
                return ['com' => 'https://czds-api.icann.org/czds/downloads/com.zone'];
            }

            public function head(string $url, string $token): array
            {
                return [
                    'status' => 200,
                    'length' => 10,
                    'last_modified' => $this->lastModified,
                ];
            }

            public function download(string $url, string $token, string $targetPath, callable $onProgress): array
            {
                file_put_contents($targetPath, 'zone-file');
                $onProgress(9, 9);

                return ['status' => 200, 'bytes' => 9, 'path' => $targetPath];
            }
        };

        (new DownloadZones('22222222-2222-4222-8222-222222222222'))
            ->handle($client, app(ZoneRegistry::class));

        Sleep::assertNeverSlept();

        $zone = ZoneTld::where('tld', 'com')->firstOrFail();
        $this->assertSame('downloaded', $zone->status);
        $this->assertNotNull($zone->last_modified);
        $this->assertSame($modified->timestamp, $zone->last_modified->timestamp);

        $this->assertFileExists(config('zones.dir').'/com.zone.gz');
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/dropwatch-download-'.bin2hex(random_bytes(4));

        mkdir($dir, 0755, true);

        $this->beforeApplicationDestroyed(function () use ($dir): void {
            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($dir);
        });

        return $dir;
    }
}
