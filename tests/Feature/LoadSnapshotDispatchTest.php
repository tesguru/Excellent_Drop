<?php

namespace Tests\Feature;

use App\Jobs\DiffZones;
use App\Jobs\LoadSnapshot;
use App\Models\ZoneTld;
use App\Services\Zone\ZoneLoader;
use App\Services\ZonePaths;
use App\Services\ZoneRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoadSnapshotDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_diff_is_dispatched_once_per_run(): void
    {
        Queue::fake();
        Cache::flush();

        $dir = $this->tempDir();

        config(['zones.dir' => $dir]);

        $runId = (string) Str::uuid();
        $today = now()->format('Y-m-d');

        foreach (['com', 'net'] as $tld) {
            ZoneTld::create([
                'tld' => $tld,
                'status' => 'downloaded',
                'last_download_at' => now(),
            ]);

            file_put_contents(
                (new ZonePaths)->namesFile($tld),
                "name\n".$tld."example\n"
            );
        }

        $job = new LoadSnapshot($runId, 'com');
        $job->handle(app(ZoneLoader::class), app(ZonePaths::class), app(ZoneRegistry::class));

        // net is still unloaded, so no diff yet.
        Queue::assertNotPushed(DiffZones::class);

        $job = new LoadSnapshot($runId, 'net');
        $job->handle(app(ZoneLoader::class), app(ZonePaths::class), app(ZoneRegistry::class));

        Queue::assertPushed(DiffZones::class, 1);

        // Running the same load step again must not queue a second diff.
        (new LoadSnapshot($runId, 'net'))->handle(
            app(ZoneLoader::class),
            app(ZonePaths::class),
            app(ZoneRegistry::class),
        );

        Queue::assertPushed(DiffZones::class, 1);

        foreach (['com', 'net'] as $tld) {
            @unlink((new ZonePaths)->namesFile($tld));
        }

        @rmdir($dir);
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/dropwatch-load-'.uniqid();

        mkdir($dir, 0755, true);

        return $dir;
    }
}
