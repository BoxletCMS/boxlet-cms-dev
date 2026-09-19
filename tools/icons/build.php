<?php

/**
 * Builds public/assets/vendor/icons.svg: the admin's icons as one SVG sprite (PLAN.md D-037).
 *
 *   php tools/icons/build.php
 *
 * For maintainers only, like the TipTap recipe beside it — and smaller: no npm and no
 * node_modules. Each icon is fetched from Lucide's published package at a pinned version and
 * written as a <symbol>. Nothing installs, builds or fetches anything at run time: the sprite
 * is committed and served as a file.
 *
 * To add an icon, add its Lucide name to ICONS and run this again. The admin refers to it as
 * icon('name'); a name that is not in the sprite draws nothing, so check the screen.
 */

const VERSION = '1.47.0';

const ICONS = [
    'arrow-down', 'arrow-up', 'chevron-down', 'cloud-upload', 'copy', 'crop', 'external-link',
    'grip-vertical', 'image-up', 'languages', 'log-out', 'menu', 'monitor', 'smartphone', 'tablet', 'pencil', 'plus', 'replace', 'search', 'settings',
    'trash-2', 'x',
    // The rail and the Workbench screens (D-052).
    'chart-column', 'check', 'circle-alert', 'clock', 'ellipsis-vertical', 'file-text', 'gauge', 'history',
    'image', 'list', 'list-checks', 'palette', 'panels-top-left',
];

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$base = 'https://cdn.jsdelivr.net/npm/lucide-static@' . VERSION;
$licence = @file_get_contents($base . '/LICENSE');
if ($licence === false) {
    fwrite(STDERR, "Could not fetch the licence from {$base}.\n");
    exit(1);
}
// The whole text, including the notice for icons derived from Feather (MIT): which of
// ours those are is Lucide's list to keep, not ours to guess.
// An XML comment may not hold two hyphens in a row, so every run of them is broken up.
$licence = trim((string) preg_replace('~-(?=-)~', '- ', $licence));

$symbols = [];
foreach (ICONS as $name) {
    $svg = @file_get_contents("{$base}/icons/{$name}.svg");
    if ($svg === false || !preg_match('~<svg[^>]*>(.*)</svg>~s', $svg, $inner)) {
        fwrite(STDERR, "Could not fetch the icon {$name}.\n");
        exit(1);
    }
    // Only drawing elements survive: the sprite is served to the admin, and nothing but
    // shapes has any business in it.
    $body = trim((string) preg_replace('~<(?!/?(?:path|circle|rect|line|polyline|polygon|ellipse)\b)[^>]*>~', '', $inner[1]));
    $body = (string) preg_replace('~\s+~', ' ', $body);
    $symbols[] = "  <symbol id=\"i-{$name}\" viewBox=\"0 0 24 24\"><g fill=\"none\" stroke=\"currentColor\" "
        . "stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\">{$body}</g></symbol>";
}

$out = "<!--\n  Lucide icons " . VERSION . ", from lucide-static (https://lucide.dev), ISC licence.\n"
    . "  Built by tools/icons/build.php; do not edit by hand. Icons: " . implode(', ', ICONS) . ".\n\n"
    . "  " . str_replace("\n", "\n  ", $licence) . "\n-->\n"
    . "<svg xmlns=\"http://www.w3.org/2000/svg\">\n" . implode("\n", $symbols) . "\n</svg>\n";

// Checked before it is written: one malformed byte and the browser draws no icon at all,
// with nothing on screen to say why. That is how the first build went out.
$check = new DOMDocument();
if (!@$check->loadXML($out)) {
    fwrite(STDERR, "The sprite is not well-formed XML; nothing was written.\n");
    exit(1);
}

file_put_contents(dirname(__DIR__, 2) . '/public/assets/vendor/icons.svg', $out);
echo 'Wrote ' . count(ICONS) . " icons.\n";
