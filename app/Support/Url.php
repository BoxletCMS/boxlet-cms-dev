<?php

namespace App\Support;

/**
 * The only place URLs are built. Templates and controllers never concatenate paths.
 *
 * Slice 1 supports "always prefix the locale". Omitting the prefix for the default
 * locale (SPEC §5.1) is a change to page() alone.
 */
final class Url
{
    private static string $basePath = '';
    private static bool $prettyUrls = true;

    public static function configure(string $basePath, bool $prettyUrls): void
    {
        self::$basePath = rtrim($basePath, '/');
        self::$prettyUrls = $prettyUrls;
    }

    /**
     * URL of a front-end page. An empty slug is the locale's home page: /{locale}/
     */
    public static function page(string $locale, string $slug = ''): string
    {
        $segments = array_map('rawurlencode', array_filter(explode('/', $slug), 'strlen'));
        $path = '/' . rawurlencode($locale) . '/' . implode('/', $segments);

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
