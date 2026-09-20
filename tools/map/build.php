<?php

/**
 * Builds public/assets/vendor/world-map.svg: the world as one SVG, each country a path
 * carrying its ISO code (PLAN.md O-20).
 *
 *   php tools/map/build.php
 *
 * For maintainers only, like the icon sprite beside it: no npm, no node_modules, nothing
 * fetched or built at run time. The map is committed and served as a file.
 *
 * Where it comes from:
 *   - world-atlas (ISC, https://github.com/topojson/world-atlas), a pre-built TopoJSON of
 *     Natural Earth's 1:110m countries. Natural Earth itself is public domain.
 *   - world-countries (MIT, https://github.com/mledoze/countries) for the numeric-to-ISO
 *     mapping, which the map file does not carry. Fetched here, never shipped.
 *
 * The projection is equirectangular — longitude and latitude straight onto x and y. It is
 * the one projection that needs no library and no trigonometry, and for "which countries
 * do visitors come from" it is enough: nothing here is measured off the map.
 */

const WIDTH = 1000.0;
const PRECISION = 1;
/** Antarctica and the far north are dropped: nobody's visitors live there, and they are half the height. */
const TOP_LAT = 84.0;
const BOTTOM_LAT = -56.0;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$topology = fetchJson('https://cdn.jsdelivr.net/npm/world-atlas@2.0.2/countries-110m.json');
$countries = fetchJson('https://cdn.jsdelivr.net/npm/world-countries@5.1.0/countries.json');

/** ISO 3166-1 numeric => [alpha-2, name]. */
$codes = [];
foreach ($countries as $country) {
    if (isset($country['ccn3'], $country['cca2'])) {
        $codes[ltrim((string) $country['ccn3'], '0')] = [(string) $country['cca2'], (string) ($country['name']['common'] ?? $country['cca2'])];
    }
}

$scale = $topology['transform']['scale'];
$translate = $topology['transform']['translate'];

/** Every arc, decoded from its deltas into [longitude, latitude] points. */
$arcs = [];
foreach ($topology['arcs'] as $encoded) {
    $points = [];
    $x = 0;
    $y = 0;
    foreach ($encoded as $delta) {
        $x += $delta[0];
        $y += $delta[1];
        $points[] = [$x * $scale[0] + $translate[0], $y * $scale[1] + $translate[1]];
    }
    $arcs[] = $points;
}

$height = round(WIDTH * (TOP_LAT - BOTTOM_LAT) / 360.0, PRECISION);
$project = static fn (array $point): array => [
    round((($point[0] + 180.0) / 360.0) * WIDTH, PRECISION),
    round(((TOP_LAT - $point[1]) / 360.0) * WIDTH, PRECISION),
];

/** One ring of a polygon: its arcs joined, a negative index meaning the arc backwards. */
$ring = static function (array $indexes) use ($arcs, $project): string {
    $points = [];
    foreach ($indexes as $index) {
        $arc = $index < 0 ? array_reverse($arcs[~$index]) : $arcs[$index];
        foreach ($arc as $position => $point) {
            // The last point of one arc is the first of the next.
            if ($position === 0 && $points !== []) {
                continue;
            }
            $points[] = $project($point);
        }
    }
    if (count($points) < 3) {
        return '';
    }

    $d = '';
    $previous = null;
    foreach ($points as [$x, $y]) {
        // Points that land on the same place after rounding add nothing but bytes.
        if ($previous !== null && $previous === [$x, $y]) {
            continue;
        }
        // A jump across half the world is a country crossing the date line — Russia, Fiji,
        // the Aleutians — which on a flat map is not a line to draw. The path starts again
        // on the other side instead; drawn, it was a stripe across the whole map.
        $jumped = $previous !== null && abs($x - $previous[0]) > WIDTH / 2;
        $d .= ($previous === null || $jumped ? 'M' : 'L') . $x . ' ' . $y;
        $previous = [$x, $y];
    }

    return $d . 'Z';
};

$paths = [];
$missing = [];
foreach ($topology['objects']['countries']['geometries'] as $geometry) {
    // Both sides without leading zeros: the map writes 032 where the code table writes 32.
    $id = ltrim((string) ($geometry['id'] ?? ''), '0');
    if (!isset($codes[$id])) {
        $missing[] = ($geometry['properties']['name'] ?? '?') . ' (' . $id . ')';
        continue;
    }
    [$code, $name] = $codes[$id];
    // Antarctica is below the map's own window and only ever drew a line along its foot.
    if ($code === 'AQ') {
        continue;
    }

    $polygons = $geometry['type'] === 'Polygon' ? [$geometry['arcs']] : $geometry['arcs'];
    $d = '';
    foreach ($polygons as $polygon) {
        foreach ($polygon as $indexes) {
            $d .= $ring($indexes);
        }
    }
    if ($d === '') {
        continue;
    }
    $paths[$code] = '  <path id="c-' . $code . '" data-name="' . htmlspecialchars($name, ENT_QUOTES | ENT_XML1) . '" d="' . $d . '"/>';
}

ksort($paths);

$svg = "<!--\n  The world's countries, each path carrying its ISO 3166-1 alpha-2 code as c-XX.\n"
    . "  Built by tools/map/build.php; do not edit by hand.\n\n"
    . "  Shapes: Natural Earth 1:110m (public domain), through world-atlas 2.0.2 (ISC).\n"
    . "  Codes and names: world-countries 5.1.0 (MIT).\n"
    . "  Equirectangular, longitude -180..180 and latitude " . BOTTOM_LAT . ".." . TOP_LAT . ".\n-->\n"
    . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . WIDTH . ' ' . $height . '" role="img">' . "\n"
    . implode("\n", $paths) . "\n</svg>\n";

$check = new DOMDocument();
if (!@$check->loadXML($svg)) {
    fwrite(STDERR, "The map is not well-formed XML; nothing was written.\n");
    exit(1);
}

file_put_contents(dirname(__DIR__, 2) . '/public/assets/vendor/world-map.svg', $svg);
printf("Wrote %d countries, %.1f KB.%s\n", count($paths), strlen($svg) / 1024, $missing === [] ? '' : ' Without a code: ' . implode(', ', $missing));

/**
 * @return array<mixed>
 */
function fetchJson(string $url): array
{
    $body = @file_get_contents($url);
    if ($body === false) {
        fwrite(STDERR, "Could not fetch {$url}.\n");
        exit(1);
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        fwrite(STDERR, "{$url} is not JSON.\n");
        exit(1);
    }

    return $data;
}
