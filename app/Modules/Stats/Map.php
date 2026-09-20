<?php

namespace App\Modules\Stats;

use App\Support\Url;

/**
 * The world map on the Statistics screen (PLAN.md O-20): every country a path in
 * public/assets/vendor/world-map.svg, shaded by how many visitors came from it, and a link
 * that narrows the screen to that country.
 *
 * Drawn on the server, like the trend chart: no map library, no script, no tiles fetched
 * from anyone. The shading is five classes in admin-stats-map.css — a fill in a style
 * attribute would be refused by the admin's CSP.
 *
 * The map is inlined rather than put in an <img> because an image cannot carry a link per
 * country, a tooltip per country, or the admin's colours.
 */
final class Map
{
    public const FILE = 'assets/vendor/world-map.svg';

    /** How many steps of shading there are; a country with none keeps the quiet fill. */
    private const STEPS = 5;

    /**
     * @param array<string, int> $visitors ISO code (upper case) => visitors
     * @param callable(string): string $link where a country's own view lives
     */
    public static function draw(string $file, array $visitors, callable $link): string
    {
        // Asked before it is read: an error handler that ignores @ turns a missing file into
        // an exception, and a site without the map file simply shows the table instead.
        $svg = is_file($file) ? (string) file_get_contents($file) : '';
        if ($svg === '') {
            return '';
        }
        $most = max([1, ...array_values($visitors)]);

        return (string) preg_replace_callback(
            '~<path id="c-([A-Z]{2})" data-name="([^"]*)" (d="[^"]*")/>~',
            static function (array $path) use ($visitors, $most, $link): string {
                [, $code, $name, $shape] = $path;
                $count = $visitors[$code] ?? 0;
                if ($count === 0) {
                    return '<path class="map-quiet" ' . $shape . '/>';
                }

                // Five steps by share of the busiest country, so one large country cannot
                // leave every other one at the same shade.
                $step = min(self::STEPS, max(1, (int) ceil($count / $most * self::STEPS)));
                $said = $name . ' · ' . t('stats.map_visitors', ['count' => number_format($count)]);

                return '<a href="' . e($link($code)) . '" aria-label="' . e($said) . '">'
                    . '<path class="map-step-' . $step . '" ' . $shape . '><title>' . e($said) . '</title></path></a>';
            },
            $svg,
        );
    }

    /** Where the file is on this install. */
    public static function path(string $publicPath): string
    {
        return rtrim($publicPath, '/') . '/' . self::FILE;
    }

    /** Whether the map can be drawn at all: the file ships with Boxlet, so normally yes. */
    public static function exists(string $publicPath): bool
    {
        return is_file(self::path($publicPath));
    }

    /** The address of the map file, for anything that wants it as a picture. */
    public static function url(): string
    {
        return Url::asset(self::FILE);
    }
}
