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
     * URL inside the admin, which never carries a locale prefix, from path segments:
     * admin('login'), admin('pages', 12, 'status').
     */
    public static function admin(string|int ...$segments): string
    {
        $path = '';
        foreach ($segments as $segment) {
            $segment = trim((string) $segment, '/');
            if ($segment !== '') {
                $path .= '/' . rawurlencode($segment);
            }
        }

        return self::$basePath . '/admin' . $path;
    }

    /**
     * This site's absolute base URL as the server itself is configured (SERVER_NAME,
     * never the client's Host header), for RewriteCheck in the installer.
     */
    public static function serverBase(bool $https, string $serverName, int $port): string
    {
        $default = $https ? 443 : 80;
        $origin = ($https ? 'https://' : 'http://') . $serverName;

        return $origin . ($port === 0 || $port === $default ? '' : ':' . $port) . self::$basePath;
    }

    /**
     * URL of a static file under public/, e.g. asset('cache/tokens.css').
     */
    public static function asset(string $path): string
    {
        return self::$basePath . '/' . ltrim($path, '/');
    }
}
