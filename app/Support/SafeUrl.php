<?php

namespace App\Support;

/**
 * Decides whether a URL typed by the admin may become a link: site-relative paths,
 * fragments and queries, or http, https, mailto and tel. Everything else, including
 * javascript: and scheme-relative //host, is refused.
 */
final class SafeUrl
{
    public const SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function isAllowed(string $url): bool
    {
        // Browsers ignore whitespace and control characters inside a scheme
        // ("java\tscript:"), and read "/\host" like "//host"; refuse both outright.
        if ($url === '' || preg_match('~[\x00-\x20\x7F\\\\]~', $url) || str_starts_with($url, '//')) {
            return false;
        }
        if (preg_match('~^[/#?]~', $url)) {
            return true;
        }

        return (bool) preg_match('~^(?:' . implode('|', self::SCHEMES) . '):~i', $url);
    }
}
