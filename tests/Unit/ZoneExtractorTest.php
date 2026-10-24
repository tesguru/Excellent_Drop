<?php

namespace Tests\Unit;

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\Zone\ZoneExtractor;
use Tests\TestCase;

class ZoneExtractorTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/dropwatch-extractor-'.bin2hex(random_bytes(4));

        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->workDir);

        parent::tearDown();
    }

    public function test_it_extracts_only_second_level_names(): void
    {
        $gz = $this->zoneFile([
            '; zone file',
            'AcmePlumbing.com.	3600	IN	NS	A.gtld-servers.net.',
            'acmeplumbing.com.	3600	IN	NS	B.gtld-servers.net.',
            'acme-plumbing.net.	3600	IN	NS	ns1.example.com.',
            'getacmeplumbing.org.	3600	IN	NS	ns2.example.com.',
            'foo.bar.example.com.	3600	IN	NS	ns3.example.com.',
            'com.	3600	IN	NS	ns4.example.com.',
            'xn--80ak6aa92e.com.	3600	IN	NS	ns6.example.com.',
        ]);

        $extractor = $this->extractor();
        $out = $this->workDir.'/com.names.tsv';

        $result = $extractor->extract($gz, 'com', $out);

        $rows = array_filter(explode("\n", (string) file_get_contents($out)));

        $pairs = [];

        foreach ($rows as $line) {
            [$name, $nameservers] = explode("\t", $line);
            $pairs[$name] = $nameservers;
        }

        $this->assertSame(2, $result['rows']);
        $this->assertSame(['acmeplumbing', 'xn--80ak6aa92e'], array_keys($pairs));
        $this->assertSame('a.gtld-servers.net,b.gtld-servers.net', $pairs['acmeplumbing']);
        $this->assertSame('ns6.example.com', $pairs['xn--80ak6aa92e']);
    }

    public function test_it_reports_the_layout_of_a_zone_file(): void
    {
        $gz = $this->zoneFile([
            'acmeplumbing.com.	3600	IN	NS	a.gtld-servers.net.',
        ]);

        $layout = $this->extractor()->verifyLayout($gz, 'com');

        $this->assertTrue($layout['ok']);
        $this->assertSame(4, $layout['columns']);
    }

    public function test_it_refuses_a_file_that_does_not_exist(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->extractor()->extract($this->workDir.'/missing.zone.gz', 'com', $this->workDir.'/out.tsv');
    }

    private function extractor(): ZoneExtractor
    {
        return new ZoneExtractor(
            [
                'sort_tmp_dir' => $this->workDir,
                'cat_binary' => 'gzip -dc',
            ],
            new ClickHouseClient(config('zones.clickhouse')),
        );
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function zoneFile(array $lines): string
    {
        $path = $this->workDir.'/test.zone.gz';

        file_put_contents($path, (string) gzencode(implode("\n", $lines)."\n"));

        return $path;
    }
}
