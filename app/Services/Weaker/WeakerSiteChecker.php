<?php

namespace App\Services\Weaker;

use Illuminate\Support\Facades\Log;

/**
 * Light website check for a weaker version.
 *
 * Only candidates are checked, so this stays cheap: one request for the home
 * page, a contact page guess from the links on it and a DNS lookup for the
 * nameservers.
 */
class WeakerSiteChecker
{
    private const MAX_BODY_BYTES = 262144;

    /**
     * @return array{http_status:int|null, has_contact_page:bool, title:?string, nameservers:array<int,string>, error:?string}
     */
    public function check(string $domain): array
    {
        $result = [
            'http_status' => null,
            'has_contact_page' => false,
            'title' => null,
            'nameservers' => $this->nameservers($domain),
            'error' => null,
        ];

        $ch = curl_init('https://'.$domain);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_USERAGENT => 'DropWatch/1.0 (candidate check)',
            CURLOPT_RANGE => '0-'.self::MAX_BODY_BYTES,
            CURLOPT_ENCODING => '',
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($body === false) {
            $result['error'] = $error !== '' ? $error : 'request failed';

            return $result;
        }

        $result['http_status'] = $status;
        $result['title'] = $this->title((string) $body);
        $result['has_contact_page'] = $this->hasContactPage((string) $body);

        return $result;
    }

    /**
     * @return array<int, string>
     */
    public function nameservers(string $domain): array
    {
        try {
            $records = @dns_get_record($domain, DNS_NS);
        } catch (\Throwable $e) {
            Log::debug('DNS lookup failed', ['domain' => $domain, 'error' => $e->getMessage()]);

            return [];
        }

        if (! is_array($records)) {
            return [];
        }

        $servers = [];

        foreach ($records as $record) {
            if (! empty($record['host'])) {
                $servers[] = strtolower((string) $record['host']);
            }
        }

        return array_values(array_unique($servers));
    }

    private function title(string $body): ?string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $body, $matches) !== 1) {
            return null;
        }

        $title = trim(html_entity_decode(strip_tags($matches[1])));

        return $title === '' ? null : mb_substr($title, 0, 255);
    }

    private function hasContactPage(string $body): bool
    {
        if (preg_match('#href\s*=\s*["\'][^"\']*contact[^"\']*["\']#i', $body) === 1) {
            return true;
        }

        return preg_match('#\bcontact\s+(us|page|form)\b#i', $body) === 1;
    }
}
