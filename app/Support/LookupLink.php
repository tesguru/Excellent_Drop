<?php

namespace App\Support;

class LookupLink
{
    private const FALLBACK = 'https://rdap.org/domain/{domain}';

    public static function for(string $domain): string
    {
        $domain = strtolower(trim($domain));

        $generic = (string) config('zones.lookup_link_template', self::FALLBACK);

        $templates = self::isCom($domain)
            ? [(string) config('zones.lookup_link_template_com', self::FALLBACK), $generic, self::FALLBACK]
            : [$generic, self::FALLBACK];

        foreach (array_unique($templates) as $template) {
            $url = str_replace('{domain}', rawurlencode($domain), $template);
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

            if (in_array($scheme, ['http', 'https'], true)) {
                return $url;
            }
        }

        return str_replace('{domain}', rawurlencode($domain), self::FALLBACK);
    }

    /**
     * Whether the URL above is the .com registry rather than the bootstrap
     * service. A 404 means "not registered" only for the registry.
     */
    public static function source(string $domain): string
    {
        return self::isCom($domain) ? 'com' : 'rdap';
    }

    private static function isCom(string $domain): bool
    {
        return str_ends_with(strtolower(trim($domain)), '.com');
    }
}
