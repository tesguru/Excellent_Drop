<?php

namespace App\Services\Czds;

use Illuminate\Support\Facades\Log;

/**
 * Client for the ICANN Central Zone Data Service (CZDS) API, spec v1.0.9.
 *
 * Zone data is licensed for the account holder's own use. Nothing here may
 * be exposed publicly and credentials are only ever read from the config.
 */
class CzdsClient
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * Authenticate against the account API and return the access token.
     *
     * The token is valid for 24 hours. The endpoint is rate limited to 8
     * attempts per 5 minutes per IP, so this method never retries.
     */
    public function authenticate(): string
    {
        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');

        if ($username === '' || $password === '') {
            throw new CzdsException('CZDS credentials are not configured.');
        }

        [$status, $body] = $this->request('POST', (string) $this->config['auth_url'], null, [
            'username' => $username,
            'password' => $password,
        ]);

        if ($status === 429) {
            throw new CzdsException('CZDS login rate limit reached (429). Stopping.', 429, true);
        }

        if ($status === 409) {
            throw new CzdsException(
                'ICANN requires the new Terms & Conditions to be accepted on czds.icann.org (409).',
                409,
                true,
            );
        }

        if ($status === 401 || $status === 403) {
            throw new CzdsException("CZDS authentication failed with status {$status}.", $status, true);
        }

        if ($status !== 200) {
            throw new CzdsException("CZDS authentication failed with status {$status}.", $status, true);
        }

        $decoded = json_decode($body, true);

        $token = is_array($decoded) ? ($decoded['accessToken'] ?? $decoded['access_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw new CzdsException('CZDS authentication response did not contain an access token.');
        }

        return $token;
    }

    /**
     * Fetch the list of zone download links the account has access to.
     *
     * The list is read from the API on every run so newly approved zones are
     * picked up automatically. It is never hardcoded.
     *
     * @return array<string, string> tld => download url
     */
    public function zoneLinks(string $token): array
    {
        [$status, $body] = $this->request('GET', (string) $this->config['links_url'], $token);

        if ($status === 401) {
            throw new CzdsException('CZDS token was rejected (401).', 401, true);
        }

        if ($status === 409) {
            throw new CzdsException(
                'ICANN requires the new Terms & Conditions to be accepted on czds.icann.org (409).',
                409,
                true,
            );
        }

        if ($status !== 200) {
            throw new CzdsException("CZDS download links request failed with status {$status}.", $status, true);
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new CzdsException('CZDS download links response was not valid JSON.');
        }

        $links = [];

        foreach ($decoded as $entry) {
            $url = is_array($entry) ? ($entry['url'] ?? null) : $entry;

            if (! is_string($url) || $url === '') {
                continue;
            }

            $tld = $this->tldFromUrl($url);

            if ($tld !== null) {
                $links[$tld] = $url;
            }
        }

        ksort($links);

        return $links;
    }

    /**
     * HEAD a zone URL to learn its size before downloading it.
     *
     * @return array{status:int, length:int, last_modified:?string}
     */
    public function head(string $url, string $token): array
    {
        [$status, $body, $headers] = $this->request('HEAD', $url, $token);

        $length = 0;
        $lastModified = null;

        foreach ($headers as $header) {
            if (stripos($header, 'content-length:') === 0) {
                $length = (int) trim(substr($header, 15));
            } elseif (stripos($header, 'last-modified:') === 0) {
                $lastModified = trim(substr($header, 14));
            }
        }

        return ['status' => $status, 'length' => $length, 'last_modified' => $lastModified];
    }

    /**
     * Download a zone file to disk.
     *
     * The body is streamed straight into a `.part` file which is renamed
     * only once the transfer completed, so an interrupted download can never
     * be mistaken for a complete zone file.
     *
     * @param  callable(int, int): void  $onProgress  receives bytes done and total bytes
     * @return array{status:int, bytes:int, path:string}
     */
    public function download(string $url, string $token, string $targetPath, callable $onProgress): array
    {
        $partPath = $targetPath.'.part';

        if (! is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0755, true);
        }

        $handle = fopen($partPath, 'wb');

        if ($handle === false) {
            throw new CzdsException("Unable to open {$partPath} for writing.");
        }

        $bytes = 0;
        $lastProgressAt = time();
        $stallSeconds = (int) ($this->config['stall_seconds'] ?? 120);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_USERAGENT => (string) $this->config['user_agent'],
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token,
                'Accept: */*',
            ],
            CURLOPT_NOPROGRESS => false,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_CONNECTTIMEOUT => (int) ($this->config['timeout'] ?? 60),
            CURLOPT_XFERINFOFUNCTION => function ($resource, $size, $downloaded, $total) use (&$bytes, &$lastProgressAt, $onProgress, $stallSeconds) {
                $bytes = (int) $downloaded;

                if ($downloaded > 0) {
                    $lastProgressAt = time();
                }

                $onProgress($bytes, (int) $total);

                if ($lastProgressAt < time() - $stallSeconds) {
                    return 1; // abort: transfer stalled
                }

                return 0;
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        curl_close($ch);
        fclose($handle);

        if ($ok === false) {
            @unlink($partPath);

            throw new CzdsException("CZDS download failed: {$error}", $status, true);
        }

        if ($status === 403) {
            @unlink($partPath);

            throw new CzdsException('Access to this zone is not approved (403).', 403, false);
        }

        if ($status === 409) {
            @unlink($partPath);

            throw new CzdsException(
                'ICANN requires the new Terms & Conditions to be accepted on czds.icann.org (409).',
                409,
                true,
            );
        }

        if ($status === 429) {
            @unlink($partPath);

            throw new CzdsException('CZDS rate limit reached (429). Stopping.', 429, true);
        }

        if ($status !== 200) {
            @unlink($partPath);

            throw new CzdsException("CZDS download failed with status {$status}.", $status, true);
        }

        if (! rename($partPath, $targetPath)) {
            @unlink($partPath);

            throw new CzdsException("Unable to move {$partPath} to {$targetPath}.");
        }

        return ['status' => $status, 'bytes' => $bytes, 'path' => $targetPath];
    }

    /**
     * Extract the TLD from a CZDS download URL.
     */
    public function tldFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        if (preg_match('#/czds/downloads/([a-z0-9-]+)\.zone$#i', $path, $matches) === 1) {
            return strtolower($matches[1]);
        }

        if (preg_match('#/([a-z0-9-]+)\.zone$#i', $path, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }

    /**
     * Perform an HTTP request with the mandatory User-Agent header.
     *
     * @param  array<string, string>|null  $json
     * @return array{0:int, 1:string, 2:array<int, string>}
     */
    private function request(string $method, string $url, ?string $token, ?array $json = null): array
    {
        $headers = [
            'Accept: application/json',
        ];

        if ($token !== null) {
            $headers[] = 'Authorization: Bearer '.$token;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_USERAGENT => (string) $this->config['user_agent'],
            CURLOPT_CONNECTTIMEOUT => (int) ($this->config['timeout'] ?? 60),
            CURLOPT_TIMEOUT => (int) ($this->config['timeout'] ?? 60),
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($json, JSON_THROW_ON_ERROR);
        } elseif ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }

        $options[CURLOPT_HTTPHEADER] = $headers;

        $ch = curl_init($url);
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new CzdsException("CZDS request to {$url} failed: {$error}");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr((string) $response, 0, $headerSize);
        $body = substr((string) $response, $headerSize);

        $parsed = [];
        foreach (preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
            if (trim($line) !== '') {
                $parsed[] = trim($line);
            }
        }

        Log::debug('CZDS request', ['method' => $method, 'status' => $status]);

        return [$status, $body, $parsed];
    }
}
