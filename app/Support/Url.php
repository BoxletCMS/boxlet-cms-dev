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
    private static bool $prettyUrls = true;
    private static string $primaryLocale = '';

    public static function configure(string $basePath, bool $prettyUrls, string $primaryLocale): void
    {
        self::$basePath = rtrim($basePath, '/');
        self::$prettyUrls = $prettyUrls;
        self::$primaryLocale = $primaryLocale;
    }

    /**
     * URL of a front-end page. An empty slug is the locale's home page:
     * / for the primary locale, /{locale}/ for any other.
     */
    public static function page(string $locale, string $slug = ''): string
    {
        $segments = array_map('rawurlencode', array_filter(explode('/', $slug), 'strlen'));
        $path = '/' . implode('/', $segments);
        if ($locale !== self::$primaryLocale) {
            $path = '/' . rawurlencode($locale) . $path;
        }

        return self::route($path);
    }

    /**
     * URL of a static file under public/, e.g. asset('cache/tokens.css').
     */
    public static function asset(string $path): string
    {
        return self::$basePath . '/' . ltrim($path, '/');
    }

    private static function route(string $path): string
    {
        if (self::$prettyUrls) {
            return self::$basePath . $path;
        }

        // $path segments are already percent-encoded, and "/" is legal in a query value.
        return self::$basePath . '/index.php?route=' . $path;
    }
}
