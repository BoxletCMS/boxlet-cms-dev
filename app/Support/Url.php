<?php

namespace App\Support;

/**
 * The only place URLs are built. Templates and controllers never concatenate paths.
 *
 * The primary locale has no prefix; every other locale does (SPEC §5.1).
 */
final class Url
{
    private static string $basePath = '';
    private static string $primaryLocale = '';

    public static function configure(string $basePath, string $primaryLocale): void
    {
        self::$basePath = rtrim($basePath, '/');
        self::$primaryLocale = $primaryLocale;
    }

    /**
     * URL of a front-end page. An empty slug is the locale's home page:
     * / for the primary locale, /{locale}/ for any other.
     */
    public static function page(string $locale, string $slug = ''): string
    {
        $segments = array_map(
            'rawurlencode',
            array_filter(explode('/', $slug), static fn (string $segment): bool => $segment !== ''),
        );
        $path = '/' . implode('/', $segments);
        if ($locale !== self::$primaryLocale) {
            $path = '/' . rawurlencode($locale) . $path;
        }

        return self::$basePath . $path;
    }

    /**
     * URL of a static file under public/, e.g. asset('cache/tokens.css').
     */
    public static function asset(string $path): string
    {
        return self::$basePath . '/' . ltrim($path, '/');
    }
}
