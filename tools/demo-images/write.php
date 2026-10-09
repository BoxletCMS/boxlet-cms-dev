<?php

/*
 * WHAT A PICTURE BECOMES, and the four files that say what was done:
 *
 *   <folder>/<name>.webp      WebP, quality 80, 2000 px on its longer side at most, no EXIF or
 *                             profile; over 300 KB, quality down to 60, then smaller to 1200 px
 *   <folder>/<name>-800.webp  the same at 800 px
 *   credits.json              the source of truth, one entry per picture
 *   CREDITS.md                made from it, by folder
 *   _review.html              the contact sheet for the owner's look
 *   _rejected.json            what was refused, and why
 */

const MAX_BYTES = 300 * 1024;

/**
 * The picture read and made upright and sRGB; a print's flat paper margin trimmed when $trim.
 * Null when it cannot be read.
 */
function demo_open(string $file, bool $trim): ?Imagick
{
    try {
        $image = new Imagick($file);
    } catch (ImagickException) {
        return null;
    }
    $image->setIteratorIndex(0);
    $image = $image->getImage();
    if (method_exists($image, 'autoOrient')) {
        $image->autoOrient();
    }
    if ($image->getImageColorspace() !== Imagick::COLORSPACE_SRGB) {
        $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
    }
    if ($trim) {
        $before = $image->getImageWidth() * $image->getImageHeight();
        $trimmed = clone $image;
        $trimmed->trimImage(0.08 * $trimmed->getQuantumRange()['quantumRangeLong']);
        $trimmed->setImagePage(0, 0, 0, 0);
        // A trim that took more than half was not a margin.
        if ($trimmed->getImageWidth() * $trimmed->getImageHeight() > $before * 0.5) {
            $image = $trimmed;
        }
    }

    return $image;
}

/**
 * Written at $longest at most, as small as quality 80..60 and then a smaller size make it; its
 * size in bytes. The WebP itself is GD's: this ImageMagick (6.9.12) writes WebP at one quality
 * whatever it is told (PLAN.md O-18; measured, q80 and q40 byte-identical), and GD's imagewebp
 * takes the quality it is given. Imagick does the rest: upright, sRGB, resized.
 */
function demo_webp(Imagick $image, string $target, int $longest, int $budget): int
{
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0775, true);
    }
    // Quality down by fives to 60, then 1800, 1600, 1400 and 1200 px: a grainy poster scan was
    // 823 KB at 2000 px and quality 60.
    $steps = [[$longest, 80], [$longest, 75], [$longest, 70], [$longest, 65], [$longest, 60], [1800, 70], [1600, 70], [1600, 60], [1400, 60], [1200, 60]];
    $made = 0;
    foreach ($steps as [$side, $quality]) {
        $side = min($side, $longest);
        $copy = clone $image;
        $w = $copy->getImageWidth();
        $h = $copy->getImageHeight();
        if (max($w, $h) > $side) {
            $copy->resizeImage($w >= $h ? $side : 0, $w >= $h ? 0 : $side, Imagick::FILTER_LANCZOS, 1);
        }
        $copy->stripImage();
        $copy->setImageFormat('png');
        $gd = imagecreatefromstring($copy->getImageBlob());
        if ($gd === false) {
            return 0;
        }
        // GD writes no WebP from a palette: a print of few colours came out as 0 bytes.
        if (!imageistruecolor($gd)) {
            imagepalettetotruecolor($gd);
        }
        imagewebp($gd, $target, $quality);
        imagedestroy($gd);
        clearstatcache(true, $target);
        $made = (int) filesize($target);
        if ($made <= $budget) {
            break;
        }
    }

    return $made;
}

/** kebab-case of what the picture shows, a few words, no ids; unique in its folder. */
function demo_name(string $words, array $taken): string
{
    $text = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $words) ?: $words);
    $stop = ['a', 'an', 'the', 'of', 'on', 'in', 'with', 'and', 'at', 'to', 'for', 'by', 'from', 'photo', 'image', 'picture'];
    $parts = array_values(array_filter(preg_split('~[^a-z0-9]+~', $text) ?: [], static fn (string $w): bool => $w !== '' && !in_array($w, $stop, true) && !ctype_digit($w)));
    $base = implode('-', array_slice($parts, 0, 5)) ?: 'picture';
    $name = $base;
    for ($n = 2; in_array($name, $taken, true); $n++) {
        $name = $base . '-' . $n;
    }

    return $name;
}

/** @param list<array<string, mixed>> $credits */
function demo_write_credits(string $out, array $credits): void
{
    usort($credits, static fn (array $a, array $b): int => strcmp($a['file'], $b['file']));
    file_put_contents($out . '/credits.json', json_encode($credits, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

    $md = "# Demo image credits\n\n"
        . "Demo images are not covered by the Boxlet licence. Each image is used under the licence listed below. "
        . "CC0 and Pexels images need no attribution. Pixabay images need no attribution. Credits are provided as a courtesy and for traceability.\n\n"
        . "Generated from `credits.json` by `tools/demo-images/build.php`.\n";
    $byFolder = [];
    foreach ($credits as $c) {
        $byFolder[strtok($c['file'], '/')][] = $c;
    }
    foreach ($byFolder as $folder => $rows) {
        $md .= "\n## {$folder}\n\n| File | Author | Source | Licence |\n| --- | --- | --- | --- |\n";
        foreach ($rows as $c) {
            $author = $c['author_url'] !== '' ? '[' . demo_md($c['author']) . '](' . $c['author_url'] . ')' : demo_md($c['author']);
            $md .= '| `' . $c['file'] . '` | ' . $author . ' | [' . demo_md($c['source']) . '](' . $c['source_url'] . ') | [' . $c['license'] . '](' . $c['license_url'] . ") |\n";
        }
    }
    file_put_contents($out . '/CREDITS.md', $md);
}

function demo_md(string $text): string
{
    return str_replace(['|', '[', ']'], ['\\|', '\\[', '\\]'], $text);
}

/**
 * @param list<array<string, mixed>> $rejected
 * @param array<string, string> $removed by id, from removed.json
 */
function demo_write_rejected(string $out, array $rejected, array $removed): void
{
    // A removal made in review stays on record, whether or not this run met the picture again.
    $listed = array_column($rejected, 'id');
    foreach ($removed as $id => $why) {
        if (!in_array($id, $listed, true)) {
            $rejected[] = ['id' => $id, 'category' => strtok($why, '/'), 'title' => '', 'source' => '', 'source_url' => '', 'reason' => 'removed in review (' . $why . ')'];
        }
    }
    file_put_contents($out . '/_rejected.json', json_encode($rejected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

/**
 * The contact sheet: every accepted picture with its facts and what to look for.
 *
 * @param list<array<string, mixed>> $credits
 * @param array<string, array{target: int, accepted: int, rejected: int, missing: int, note: string}> $counts
 * @param array<string, array{bytes: int, flags: list<string>}> $extra by file
 */
function demo_write_review(string $out, array $credits, array $counts, array $extra): void
{
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $rows = '';
    foreach ($counts as $folder => $c) {
        $rows .= '<tr><td>' . $e($folder) . '</td><td>' . $c['target'] . '</td><td>' . $c['accepted'] . '</td><td>' . $c['rejected'] . '</td><td' . ($c['missing'] > 0 ? ' class="short"' : '') . '>' . $c['missing'] . '</td><td>' . $e($c['note']) . '</td></tr>';
    }
    $sections = '';
    $byFolder = [];
    foreach ($credits as $c) {
        $byFolder[strtok($c['file'], '/')][] = $c;
    }
    foreach ($byFolder as $folder => $items) {
        $cards = '';
        foreach ($items as $c) {
            $x = $extra[$c['file']] ?? ['bytes' => 0, 'flags' => []];
            $flags = $x['flags'] === [] ? '' : '<ul class="flags">' . implode('', array_map(static fn (string $f): string => '<li>' . htmlspecialchars($f) . '</li>', $x['flags'])) . '</ul>';
            $cards .= '<figure class="' . $e($c['orientation']) . '"><a href="' . $e($c['file']) . '"><img src="' . $e(substr($c['file'], 0, -5) . '-800.webp') . '" alt="' . $e($c['alt']) . '" loading="lazy"></a>'
                . '<figcaption><strong>' . $e($c['file']) . '</strong><span>' . round($x['bytes'] / 1024) . ' KB · ' . $e($c['orientation']) . ' · ' . $e($c['tone']) . ' · ' . $e($c['suggested_use']) . '</span>'
                . '<span>' . $e($c['author']) . ' · <a href="' . $e($c['source_url']) . '">' . $e($c['source']) . '</a> · ' . $e($c['license']) . '</span>'
                . '<span class="alt">' . $e($c['alt']) . '</span>' . ($c['alt_hr'] !== '' ? '<span class="alt hr">' . $e($c['alt_hr']) . '</span>' : '<span class="missing">alt_hr missing</span>') . $flags . '</figcaption></figure>';
        }
        $sections .= '<h2>' . $e($folder) . ' <small>' . count($items) . '</small></h2><div class="sheet">' . $cards . '</div>';
    }
    // A page for the Artifact viewer too: no skeleton of its own, tokens for both themes.
    $html = '<title>Printworks demo images</title>'
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap">'
        . '<style>'
        . ':root{--paper:#eef0ed;--card:#f8f9f7;--ink:#1c2227;--muted:#5b6469;--line:#d2d7d4;--accent:#0a6a85;--flag:#7a4a00;--flag-bg:#fbefd9;--flag-edge:#d99a2b}'
        . '@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){color-scheme:dark;--paper:#15191c;--card:#1d2226;--ink:#e6eae8;--muted:#a2abaf;--line:#323a3f;--accent:#62c3df;--flag:#f3c37a;--flag-bg:#33280f;--flag-edge:#a8761f}}'
        . ':root[data-theme="dark"]{color-scheme:dark;--paper:#15191c;--card:#1d2226;--ink:#e6eae8;--muted:#a2abaf;--line:#323a3f;--accent:#62c3df;--flag:#f3c37a;--flag-bg:#33280f;--flag-edge:#a8761f}'
        . 'body{background:var(--paper);color:var(--ink);font:15px/1.5 "IBM Plex Sans",system-ui,sans-serif;padding-inline:16px;padding-block:28px 48px}'
        . 'main{max-width:1280px;margin-inline:auto;display:grid;gap:12px}'
        . 'h1{margin:0;font-size:1.6rem;font-weight:600;text-wrap:balance}h2{margin:28px 0 4px;font-size:1.15rem;font-weight:600;display:flex;gap:10px;align-items:baseline}'
        . 'h2 small,.lead{color:var(--muted);font-weight:400}.mono,figcaption strong,td{font-family:"IBM Plex Mono",ui-monospace,monospace;font-variant-numeric:tabular-nums}'
        . '.wrap{overflow-x:auto}table{border-collapse:collapse;font-size:.9rem}th{text-align:left;font-weight:500;color:var(--muted);letter-spacing:.04em;text-transform:uppercase;font-size:.72rem}'
        . 'td,th{border-bottom:1px solid var(--line);padding:6px 18px 6px 0}td.short{color:var(--flag);font-weight:500}'
        . '.sheet{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px}'
        . 'figure{margin:0;background:var(--card);border:1px solid var(--line);border-radius:6px;overflow:hidden;display:grid;grid-template-rows:auto 1fr}'
        . 'figure img{display:block;width:100%;height:190px;object-fit:contain;background:var(--line)}'
        . 'figcaption{display:grid;gap:3px;padding:10px 12px 12px;font-size:.84rem}figcaption strong{font-size:.8rem;font-weight:500;overflow-wrap:anywhere}figcaption span{color:var(--muted);overflow-wrap:anywhere}'
        . 'a{color:var(--accent)}a:focus-visible{outline:2px solid var(--accent);outline-offset:2px}.alt{font-style:italic}.hr{font-style:italic;color:var(--ink)!important}'
        . '.missing{color:var(--flag)!important;font-weight:500}.flags{margin:6px 0 0;padding:6px 10px 6px 22px;background:var(--flag-bg);color:var(--flag);border-left:3px solid var(--flag-edge);border-radius:0 4px 4px 0}'
        . '</style><main><h1>Printworks demo images</h1><p class="lead">Generated ' . gmdate('Y-m-d H:i') . ' UTC by <span class="mono">tools/demo-images/build.php</span>. '
        . 'A picture opens its 2000 px file. Amber notes say what the rules could not settle from words alone. What was refused, and why, is in <a href="_rejected.json">_rejected.json</a>.</p>'
        . '<div class="wrap"><table><tr><th>Folder</th><th>Target</th><th>Accepted</th><th>Rejected</th><th>Missing</th><th>Note</th></tr>' . $rows . '</table></div>' . $sections . '</main>';
    file_put_contents($out . '/_review.html', $html);
}

/**
 * The run's table on the terminal: per folder, what was asked, taken, refused and missing.
 *
 * @param array<string, array{target: int, accepted: int, rejected: int, missing: int, note: string}> $counts
 */
function demo_print_counts(array $counts): void
{
    echo "\n" . str_pad('folder', 10) . " target accepted rejected missing\n";
    foreach ($counts as $folder => $c) {
        printf("%-10s %6d %8d %8d %7d  %s\n", $folder, $c['target'], $c['accepted'], $c['rejected'], $c['missing'], $c['note']);
    }
}
