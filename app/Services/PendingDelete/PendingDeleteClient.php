<?php

namespace App\Services\PendingDelete;

use JsonException;

/**
 * The only component that talks to PendingDelete.net.
 *
 * The manifest is small; the dataset files are downloaded with a streaming
 * write callback so a multi-megabyte JSON file is parsed row by row instead of
 * being loaded into memory. Every response is read over HTTPS only.
 */
class PendingDeleteClient
{
    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        $url = (string) config('pendingdelete.manifest_url');
        $body = $this->request($url, (int) config('pendingdelete.manifest_timeout', 30), 5242880);

        try {
            $manifest = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PendingDeleteException('The manifest is not valid JSON.');
        }

        if (! is_array($manifest)) {
            throw new PendingDeleteException('The manifest is not a JSON object.');
        }

        foreach (['built_utc', 'today', 'yesterday', 'lists', 'files'] as $key) {
            if (! array_key_exists($key, $manifest)) {
                throw new PendingDeleteException('The manifest is missing "'.$key.'".');
            }
        }

        if (! is_array($manifest['files']) || $manifest['files'] === []) {
            throw new PendingDeleteException('The manifest contains no files.');
        }

        return $manifest;
    }

    /**
     * The files for one list, in manifest order.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<int, array<string, mixed>>
     */
    public function filesFor(array $manifest, string $list): array
    {
        $files = [];

        foreach ($manifest['files'] as $file) {
            if (! is_array($file) || (string) ($file['list'] ?? '') !== $list) {
                continue;
            }

            if ((string) ($file['tld'] ?? '') === '') {
                throw new PendingDeleteException('A manifest file has no TLD.');
            }

            $files[] = $file;
        }

        return $files;
    }

    /**
     * Download one dataset and hand every row to the callback.
     *
     * Nothing is written to ClickHouse here. A truncated or malformed file
     * throws before the caller can merge anything.
     *
     * @param  callable(array<string, mixed>): void  $onRow
     * @return array{rows:int, count:?int, columns:array<int, string>}
     */
    public function stream(string $url, callable $onRow): array
    {
        $streamer = new JsonRowStreamer;
        $columns = null;

        $this->request($url, (int) config('pendingdelete.timeout', 900), null, function (string $chunk) use ($streamer, $onRow, &$columns): void {
            foreach ($streamer->feed($chunk) as $row) {
                $columns ??= $streamer->columns();
                $onRow($this->associate($row, $columns));
            }
        });

        return $streamer->finish();
    }

    /**
     * @param  array<string, mixed>  $file
     */
    public function datasetUrl(array $file): string
    {
        $format = (string) config('pendingdelete.format', 'json');
        $url = (string) ($file[$format] ?? '');

        if ($url === '') {
            throw new PendingDeleteException('The manifest has no '.$format.' URL for '.($file['tld'] ?? '?'));
        }

        $metrics = trim((string) config('pendingdelete.metrics', ''));

        if ($metrics !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query(
                ['metrics' => $metrics],
                '',
                '&',
                PHP_QUERY_RFC3986,
            );
        }

        return $url;
    }

    /**
     * @param  array<int|string, mixed>  $row
     * @param  array<int, string>  $columns
     * @return array<string, mixed>
     */
    private function associate(array $row, array $columns): array
    {
        if (! array_is_list($row)) {
            return $row;
        }

        if (count($row) !== count($columns)) {
            throw new PendingDeleteException('A dataset row does not match the declared columns.');
        }

        return array_combine($columns, $row) ?: [];
    }

    /**
     * @param  callable(string): void|null  $onChunk
     */
    private function request(string $url, int $timeout, ?int $maxBytes = null, ?callable $onChunk = null): string
    {
        $status = 0;
        $body = '';
        $handle = curl_init($url);

        if ($handle === false) {
            throw new PendingDeleteException('Could not start the HTTP request.');
        }

        $options = [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => (int) config('pendingdelete.connect_timeout', 10),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 60,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => (string) config('pendingdelete.user_agent', 'DropWatch/1.0'),
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_HEADERFUNCTION => function ($handle, string $header) use (&$status): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
                    $status = (int) $match[1];
                }

                return strlen($header);
            },
        ];

        if ($onChunk !== null) {
            $options[CURLOPT_WRITEFUNCTION] = function ($handle, string $chunk) use ($onChunk): int {
                $onChunk($chunk);

                return strlen($chunk);
            };
        } else {
            $options[CURLOPT_RETURNTRANSFER] = true;
            $options[CURLOPT_WRITEFUNCTION] = function ($handle, string $chunk) use (&$body, $maxBytes): int {
                $body .= $chunk;

                if ($maxBytes !== null && strlen($body) > $maxBytes) {
                    return 0;
                }

                return strlen($chunk);
            };
        }

        curl_setopt_array($handle, $options);

        try {
            $success = curl_exec($handle);
            $error = curl_error($handle);
        } finally {
            curl_close($handle);
        }

        if ($success === false) {
            throw new PendingDeleteException('The download failed: '.($error !== '' ? $error : 'unknown network error'));
        }

        if ($status < 200 || $status >= 300) {
            throw new PendingDeleteException('The download returned HTTP '.$status.'.');
        }

        return $body;
    }
}
