<?php

namespace Tests\Feature;

use App\Jobs\Cleanup;
use App\Models\JobRun;
use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ZonePaths;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_snapshots_older_than_yesterday_are_dropped(): void
    {
        $dir = $this->tempDir();

        config([
            'zones.dir' => $dir,
            'zones.retain_days' => 2,
            'zones.keep_raw' => false,
        ]);

        file_put_contents($dir.'/com.names.tsv', "acmeplumbing\t");
        file_put_contents($dir.'/com.zone.gz', 'zone-file');

        $client = new class extends ClickHouseClient
        {
            /** @var array<int, string> */
            public array $dropped = [];

            /** @var array<int, string> */
            private array $dates = [];

            public function __construct()
            {
                $this->dates = [
                    now()->subDays(3)->format('Y-m-d'),
                    now()->subDays(2)->format('Y-m-d'),
                    now()->subDay()->format('Y-m-d'),
                    now()->format('Y-m-d'),
                ];
            }

            public function database(): string
            {
                return 'zones_test';
            }

            public function rows(string $sql, array $params = []): array
            {
                $cutoff = (string) ($params['d'] ?? '');

                return array_values(array_map(
                    fn (string $date) => ['snapshot_date' => $date],
                    array_filter($this->dates, fn (string $date) => $date < $cutoff),
                ));
            }

            public function execute(string $sql, array $params = []): void
            {
                $this->dropped[] = (string) ($params['p'] ?? '');
            }
        };

        (new Cleanup((string) Str::uuid()))->handle($client, new ZonePaths);

        $this->assertSame([
            now()->subDays(3)->format('Y-m-d'),
            now()->subDays(2)->format('Y-m-d'),
        ], $client->dropped);

        $this->assertFileDoesNotExist($dir.'/com.names.tsv');
        $this->assertFileDoesNotExist($dir.'/com.zone.gz');

        $run = JobRun::where('step', 'cleanup')->latest('id')->firstOrFail();

        $this->assertSame(JobRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(2, $run->rows);
        $this->assertStringContainsString('Dropped 2 old partition(s)', (string) $run->message);
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/dropwatch-cleanup-'.bin2hex(random_bytes(4));

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
