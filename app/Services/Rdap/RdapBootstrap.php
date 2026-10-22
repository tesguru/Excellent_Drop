<?php

namespace App\Services\Rdap;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Resolves the RDAP server for a TLD from the IANA bootstrap file.
 */
class RdapBootstrap
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * @return array<string, string> tld => base url
     */
    public function servers(): array
    {
        return Cache::remember('rdap:bootstrap', (int) ($this->config['bootstrap_ttl'] ?? 86400), function () {
            $response = Http::timeout(30)
                ->withHeaders(['User-Agent' => 'DropWatch/1.0 (rdap bootstrap)'])
                ->get((string) $this->config['bootstrap_url']);

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();
            $servers = [];

            foreach ($data['services'] ?? [] as $service) {
                // The IANA file stores [[tlds], [urls]] per service.
                $tlds = $service[0] ?? [];
                $urls = $service[1] ?? [];

                if (! is_array($urls) || ! is_array($tlds) || $urls === []) {
                    continue;
                }

                foreach ($tlds as $tld) {
                    $servers[strtolower((string) $tld)] = rtrim((string) $urls[0], '/');
                }
            }

            return $servers;
        });
    }

    public function serverFor(string $tld): ?string
    {
        return $this->servers()[strtolower($tld)] ?? null;
    }
}
