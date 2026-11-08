<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class NetworkPolicyTest extends TestCase
{
    /**
     * The only two files allowed to make an outbound HTTP call.
     *
     * @return array<int, string>
     */
    public static function allowedFiles(): array
    {
        return [
            'Services/Czds/CzdsClient.php',
            'Services/ClickHouse/ClickHouseClient.php',
        ];
    }

    public function test_only_the_czds_and_clickhouse_clients_make_http_calls(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            $relative = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

            if (in_array($relative, self::allowedFiles(), true)) {
                continue;
            }

            $source = $file->getContents();

            foreach ($this->httpPatterns() as $label => $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $offenders[] = $relative.' uses '.$label;
                }
            }
        }

        $this->assertSame([], $offenders, 'Outbound HTTP is only allowed in the CZDS and ClickHouse clients.');
    }

    public function test_the_allowed_clients_exist(): void
    {
        foreach (self::allowedFiles() as $relative) {
            $this->assertFileExists(app_path($relative));
        }
    }

    /**
     * @return array<string, string>
     */
    private function httpPatterns(): array
    {
        return [
            'the HTTP facade' => '/\bHttp::/',
            'curl' => '/\bcurl_(init|exec|multi_exec)\s*\(/',
            'Guzzle' => '/\bGuzzleHttp\b/',
            'raw sockets' => '/\b(fsockopen|stream_socket_client)\s*\(/',
            'a remote file read' => '/\b(file_get_contents|fopen)\s*\(\s*[\'"]https?:/',
        ];
    }
}
