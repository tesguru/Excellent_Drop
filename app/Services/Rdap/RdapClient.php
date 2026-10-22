<?php

namespace App\Services\Rdap;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin RDAP client.
 *
 * Requests are throttled to roughly one per second per server, the
 * Retry-After header is honoured on 429 and every result is cached so the
 * same domain is not looked up twice in a day.
 */
class RdapClient
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly RdapBootstrap $bootstrap,
    ) {}

    /**
     * Look a domain up on its RDAP server.
     *
     * @return array{
     *     found:bool,
     *     status:?string,
     *     statuses:array<int, string>,
     *     expiry:?string,
     *     pending_delete_date:?string,
     *     redemption_date:?string,
     *     raw_statuses:array<int, string>,
     *     error:?string
     * }
     */
    public function lookup(string $domain): array
    {
        $tld = $this->tld($domain);
        $server = $this->bootstrap->serverFor($tld);

        if ($server === null) {
            return $this->emptyResult('No RDAP server known for '.$tld);
        }

        $url = $server.'/domain/'.rawurlencode($domain);

        $response = $this->throttledRequest($server, $url);

        if ($response === null) {
            return $this->emptyResult('RDAP request failed');
        }

        if ($response->status() === 404) {
            return $this->emptyResult('Domain not found in RDAP');
        }

        if (! $response->successful()) {
            return $this->emptyResult('RDAP status '.$response->status());
        }

        $data = $response->json();

        if (! is_array($data)) {
            return $this->emptyResult('Unexpected RDAP payload');
        }

        $rawStatuses = array_values(array_filter((array) ($data['status'] ?? []), 'is_string'));
        $events = [];
        $expiry = null;
        $pendingDelete = null;
        $redemption = null;

        foreach ((array) ($data['events'] ?? []) as $event) {
            if (! is_array($event)) {
                continue;
            }

            $action = strtolower((string) ($event['eventAction'] ?? ''));
            $date = $this->normaliseDate($event['eventDate'] ?? null);

            if ($date === null) {
                continue;
            }

            $events[] = ['action' => $action, 'date' => $date];

            if ($action === 'expiration') {
                $expiry = $date;
            } elseif ($action === 'pending delete') {
                $pendingDelete = $date;
            } elseif ($action === 'redemption period') {
                $redemption = $date;
            }
        }

        return [
            'found' => true,
            'status' => $this->normaliseStatus($rawStatuses),
            'statuses' => $rawStatuses,
            'expiry' => $expiry,
            'pending_delete_date' => $pendingDelete,
            'redemption_date' => $redemption,
            'raw_statuses' => $rawStatuses,
            'error' => null,
        ];
    }

    /**
     * Normalise the RDAP status list into a single status the app stores.
     *
     * @param  array<int, string>  $statuses
     */
    public function normaliseStatus(array $statuses): string
    {
        $lowered = array_map('strtolower', $statuses);

        if (in_array('pending delete', $lowered, true)) {
            return 'pending delete';
        }

        if (in_array('redemption period', $lowered, true)) {
            return 'redemption period';
        }

        foreach (['client hold', 'server hold', 'registry hold'] as $hold) {
            if (in_array($hold, $lowered, true)) {
                return 'hold';
            }
        }

        if ($statuses === []) {
            return 'active';
        }

        return (string) $statuses[0];
    }

    private function tld(string $domain): string
    {
        $parts = explode('.', $domain);

        return strtolower((string) end($parts));
    }

    private function normaliseDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *     found:bool,
     *     status:?string,
     *     statuses:array<int, string>,
     *     expiry:?string,
     *     pending_delete_date:?string,
     *     redemption_date:?string,
     *     raw_statuses:array<int, string>,
     *     error:?string
     * }
     */
    private function emptyResult(?string $error): array
    {
        return [
            'found' => false,
            'status' => null,
            'statuses' => [],
            'expiry' => null,
            'pending_delete_date' => null,
            'redemption_date' => null,
            'raw_statuses' => [],
            'error' => $error,
        ];
    }

    /**
     * Perform a throttled request, honouring Retry-After on 429.
     */
    private function throttledRequest(string $server, string $url): ?Response
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->waitForSlot($server);

            $response = Http::timeout((int) ($this->config['timeout'] ?? 20))
                ->withHeaders(['User-Agent' => 'DropWatch/1.0 (rdap)'])
                ->get($url);

            if ($response->status() !== 429) {
                return $response;
            }

            $retryAfter = (int) $response->header('Retry-After') ?: 5;
            sleep(min($retryAfter, 60));
        }

        return null;
    }

    private function waitForSlot(string $server): void
    {
        $key = 'rdap:throttle:'.sha1($server);
        $minInterval = (int) ($this->config['min_interval_ms'] ?? 1000) / 1000;

        $last = (float) Cache::get($key, 0);

        $now = microtime(true);
        $wait = ($last + $minInterval) - $now;

        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }

        Cache::put($key, microtime(true), now()->addHours(6));
    }
}
