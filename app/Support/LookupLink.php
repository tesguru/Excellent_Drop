<?php

namespace App\Support;

class LookupLink
{
    public static function for(string $domain): string
    {
        $template = (string) config('zones.lookup_link_template', 'https://rdap.org/domain/{domain}');

        $url = str_replace('{domain}', rawurlencode(strtolower(trim($domain))), $template);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true)
            ? $url
            : 'https://rdap.org/domain/'.rawurlencode(strtolower(trim($domain)));
    }
}
