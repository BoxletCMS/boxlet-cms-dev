<?php

/**
 * Collects the demo site's pictures (PLAN.md D-207): The Printworks, a community arts centre in
 * a former printing works. For maintainers only, like the icon sprite and the release ZIP.
 *
 *   php tools/demo-images/build.php                       everything, from the cache where it can
 *   php tools/demo-images/build.php --remove=space/a.webp,art/b.webp [--reason="..."]
 *                                                         those out (the owner's review), the next
 *                                                         candidate in their place; the reason goes
 *                                                         into _rejected.json with them
 *
 * Writes demo_images/ at the repository's root: the pictures by folder, credits.json, CREDITS.md,
 * _review.html and _rejected.json. Everything fetched is kept in demo_images/.cache, so a rerun
 * asks no source for anything it has already said, and gives the same result.
 *
 * Needs PEXELS_API_KEY (in .env or the environment) for the folders Pexels serves; without it
 * those are reported missing. Faces are found with OpenCV's YuNet (faces.py) where
 * DEMO_FACES_PYTHON (default ~/boxlet-build/venv-faces/bin/python) has OpenCV 4 and
 * DEMO_FACES_MODEL (default ~/boxlet-build/yunet.onnx) is there; without them they are flagged
 * for the review instead.
 * Alt texts in Croatian, and better English ones, are tools/demo-images/alt.json, by source id.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/http.php';
require __DIR__ . '/sources.php';
require __DIR__ . '/checks.php';
require __DIR__ . '/write.php';

$root = dirname(__DIR__, 2);
$config = require __DIR__ . '/config.php';
$out = $root . '/demo_images';
$cache = $out . '/.cache';
$options = getopt('', ['remove:', 'reason:']);

require __DIR__ . '/setup.php';

$alt = is_file(__DIR__ . '/alt.json') ? (array) json_decode((string) file_get_contents(__DIR__ . '/alt.json'), true) : [];

$credits = [];
$rejected = [];
$counts = [];
$extra = [];
$hashes = [];
$seen = [];
$state = [];

echo $python === null ? "Faces: no detector, flagged for the review instead.\n" : "Faces: OpenCV at {$python}\n";

foreach ($config['categories'] as $category => $spec) {
    foreach (glob($out . '/' . $category . '/*.webp') ?: [] as $old) {
        unlink($old);
    }
    $target = (int) $spec['target'];
    $quota = ['landscape' => (int) round($target * 0.6), 'portrait' => (int) round($target * 0.25)];
    $quota['square'] = $target - $quota['landscape'] - $quota['portrait'];
    $have = ['landscape' => 0, 'portrait' => 0, 'square' => 0];
    $names = [];
    $spares = [];
    $tried = 0;
    $refusedHere = 0;
    $searched = false;
    $notes = [];
    $accepted = 0;
    $perQuery = [];
    $photo = $category !== 'art' && $category !== 'archive';
    $trim = !$photo;
    echo "\n{$category}: target {$target}\n";
    $steps = array_map(static fn ($s): array => is_array($s) ? $s : ['source' => $s], $spec['sources']);
    // A folder whose first source cannot be asked waits for it: the later ones only fill its gaps.
    $first = $steps[0]['source'];
    if (isset($needs[$first]) && $env[$first . '_key'] === '') {
        echo "  waiting for {$needs[$first]}\n";
        $counts[$category] = ['target' => $target, 'accepted' => 0, 'rejected' => 0, 'missing' => $target, 'note' => 'waiting for ' . $needs[$first]];
        continue;
    }

    $refuse = static function (array $c, string $why) use (&$rejected, &$refusedHere, $category): void {
        $rejected[] = ['id' => $c['key'], 'category' => $category, 'title' => $c['title'], 'source' => $c['source'], 'source_url' => $c['source_url'], 'reason' => $why];
        $refusedHere++;
    };

    $accept = function (array $c, Imagick $image, array $look) use (&$credits, &$extra, &$hashes, &$names, &$state, &$accepted, &$have, $category, $out, $cache, $python, $alt, $photo, $refuse): bool {
        $flags = demo_flags($c, $category, $python !== null);
        $faces = [];
        if ($photo || $category === 'archive') {
            $probe = $cache . '/checks/' . preg_replace('~[^a-z0-9]+~i', '-', $c['key']) . '.jpg';
            if (!is_file($probe)) {
                @mkdir(dirname($probe), 0775, true);
                $small = clone $image;
                $small->thumbnailImage(1200, 1200, true);
                $small->setImageFormat('jpeg');
                $small->writeImage($probe);
            }
            $faces = demo_faces([$probe], $python)[$probe] ?? [];
            if ($faces !== [] && $photo) {
                $refuse($c, 'a recognisable face (' . count($faces) . ' found by the detector)');

                return false;
            }
            if ($faces !== []) {
                $flags[] = count($faces) . ' face(s) found: an old photograph, kept for your look';
            }
        }
        $words = $alt[$c['key']] ?? [];
        // What I saw and could not settle myself: the owner's call, in alt.json's "look".
        if (isset($words['look'])) {
            $flags[] = (string) $words['look'];
        }
        $english = (string) ($words['alt'] ?? '');
        if ($english === '') {
            $english = $category === 'art' && $c['author'] !== 'Unknown' ? $c['title'] . ', by ' . $c['author'] : ($c['alt'] !== '' ? $c['alt'] : $c['title']);
        }
        $name = demo_name((string) ($words['name'] ?? $english), $names);
        $names[] = $name;
        $file = $category . '/' . $name . '.webp';
        $bytes = demo_webp($image, $out . '/' . $file, 2000, MAX_BYTES);
        if ($bytes > MAX_BYTES) {
            $flags[] = 'over 300 KB even at 1200 px and quality 60: ' . round($bytes / 1024) . ' KB';
        }
        demo_webp($image, $out . '/' . $category . '/' . $name . '-800.webp', 800, PHP_INT_MAX);
        $w = $image->getImageWidth();
        $h = $image->getImageHeight();
        $tone = $look['luminance'] < 0.35 ? 'dark' : ($look['luminance'] > 0.65 ? 'light' : 'mid');
        $use = match (true) {
            // The owner's choice wins (D-210: Pixabay's 1280 px is good enough for these heroes).
            isset($words['use']) => (string) $words['use'],
            $category === 'texture' => 'background',
            $category === 'art' => 'gallery',
            // Unasked, never a picture of at most 1280 px, nor an old one with people in it.
            $look['orientation'] === 'landscape' && $w / $h >= 1.45 && in_array($category, ['space', 'workshop', 'archive'], true)
                && ($c['max_source_px'] ?? 0) === 0 && !($category === 'archive' && ($words['people'] ?? false)) => 'hero',
            $look['orientation'] === 'square' || in_array($category, ['food', 'books'], true) => 'card',
            default => 'image-text',
        };
        $credits[] = [
            'file' => $file, 'title' => $c['title'], 'author' => $c['author'], 'author_url' => $c['author_url'],
            'source' => $c['source'], 'source_url' => $c['source_url'], 'license' => $c['license'], 'license_url' => $c['license_url'],
            'downloaded_at' => gmdate('c', (int) filemtime($look['original'])),
            'original_size' => [$c['width'] > 0 ? $c['width'] : $look['width'], $c['height'] > 0 ? $c['height'] : $look['height']],
            'alt' => $english, 'alt_hr' => (string) ($words['alt_hr'] ?? ''),
            'tone' => $tone, 'orientation' => $look['orientation'], 'suggested_use' => $use,
        ];
        // Where the source's largest file is smaller than the rule's 1600 px (Pixabay, D-209).
        if (($c['max_source_px'] ?? 0) > 0) {
            $credits[count($credits) - 1]['max_source_px'] = (int) $c['max_source_px'];
        }
        // Workers or other people in an old picture: the demo keeps those out of a hero (D-209).
        // Elsewhere only where the owner marked one in alt.json (D-210: a figure with no face).
        if ($category === 'archive') {
            $credits[count($credits) - 1]['people'] = (bool) ($words['people'] ?? $faces !== []);
        } elseif ($words['people'] ?? false) {
            $credits[count($credits) - 1]['people'] = true;
        }
        $extra[$file] = ['bytes' => $bytes, 'flags' => $flags, 'colour' => $look['colour']];
        $hashes[$look['hash']] = $file;
        $state[$file] = $c['key'];
        $have[$look['orientation']]++;
        $accepted++;
        printf("  + %-48s %4d KB  %s, %s\n", $file, round($bytes / 1024), $look['orientation'], $tone);

        return true;
    };

    // One candidate through every rule, and in when it passes: from a search, or fetched by its
    // id where a step names it and no search gave it.
    $consider = function (array $c, bool $chosen = false) use (&$seen, &$tried, &$hashes, &$have, &$spares, &$perQuery, $removed, $refuse, $config, $category, $cache, $env, $trim, $photo, $quota, $accept): void {
        $seen[$c['key']] = true;
        if (isset($removed[$c['key']])) {
            $refuse($c, 'removed in review (' . $removed[$c['key']] . ')');
            return;
        }
        $why = demo_refused($c, $category, $config['min_side']);
        if ($why !== null) {
            $refuse($c, $why);
            return;
        }
        $tried++;
        $original = $cache . '/originals/' . preg_replace('~[^a-z0-9]+~i', '-', $c['key']) . '.img';
        if (demo_get($c['image_url'], $original, [], $env['ua']) === null) {
            $refuse($c, 'the picture could not be downloaded');
            return;
        }
        $image = demo_open($original, $trim);
        if ($image === null) {
            $refuse($c, 'the file could not be read');
            return;
        }
        $w = $image->getImageWidth();
        $h = $image->getImageHeight();
        $floor = ($c['max_source_px'] ?? 0) > 0 ? (int) $c['max_source_px'] : $config['min_side'];
        if (max($w, $h) < $floor) {
            $refuse($c, "below {$floor} px on its longer side ({$w}×{$h}" . ($trim ? ' after its margin' : '') . ')');
            return;
        }
        if ($photo && demo_bordered($image)) {
            $refuse($c, 'a flat border round the picture');
            return;
        }
        $hash = demo_dhash($image);
        foreach ($hashes as $other => $file) {
            if (demo_distance($hash, (string) $other) <= 6) {
                $refuse($c, "a near-duplicate of {$file}");
                return;
            }
        }
        $ratio = $w / $h;
        $look = demo_light($image) + ['hash' => $hash, 'width' => $w, 'height' => $h, 'original' => $original,
            'orientation' => $ratio >= 1.2 ? 'landscape' : ($ratio <= 0.85 ? 'portrait' : 'square')];
        // A shape's share is a preference a choice made in review overrides.
        if (!$chosen && $have[$look['orientation']] >= $quota[$look['orientation']]) {
            $spares[] = [$c, $look];
            return;
        }
        if ($accept($c, $image, $look)) {
            $perQuery[$c['query']] = ($perQuery[$c['query']] ?? 0) + 1;
        }
    };

    foreach ($steps as $n => $step) {
        $source = $step['source'];
        $queries = $step['queries'] ?? $spec['queries'];
        $take = (int) ($step['take'] ?? $target);
        $stepEnd = min($target, $accepted + $take);
        // Each search gives at most its share of the step, so that one generous search does not
        // make the folder of one kind (Met's "woodblock print" filled art before a type specimen).
        $cap = (int) ceil($take / count($queries)) + 1;
        // A step's chosen pictures first, fetched by their ids (The Met's, Pixabay's), whatever
        // any search of this run gives: a choice made in review does not depend on a search's order.
        foreach ((array) ($step['prefer'] ?? []) as $want) {
            [$from, $id] = explode(':', $want, 2) + [1 => ''];
            if ($accepted >= $stepEnd || isset($seen[$want])) {
                continue;
            }
            $c = match ($from) {
                'met' => demo_met_one((int) $id, $env),
                'pixabay' => (demo_pixabay('', 1, $env, '', (int) $id) ?? [])[0] ?? null,
                default => null,
            };
            if ($c !== null) {
                $consider($c + ['query' => $n . ':chosen'], true);
            }
        }
        foreach ([1, 2, 3] as $page) {
            if ($accepted >= $stepEnd) {
                break;
            }
            $lists = [];
            foreach ($queries as $query) {
                // The step's orientation on its first page, any shape after: the portraits too.
                $searched = true;
                $found = demo_search($source, $query, $page, $env, $page === 1 ? (string) ($step['orientation'] ?? '') : '');
                if ($found === null) {
                    $notes[$source] = $source . ': not asked (no ' . ($needs[$source] ?? 'key') . ($source === 'pexels' ? ', optional)' : ')');
                    continue 3;
                }
                $lists[] = array_map(static fn (array $c): array => $c + ['query' => $n . ':' . $query], $found);
            }
            // Chosen pictures were taken above; here they only pass a search's share.
            $prefer = array_flip((array) ($step['prefer'] ?? []));
            // Round the searches, so every one gives the folder something.
            for ($i = 0; $accepted < $stepEnd && $lists !== [] && $i < max(array_map('count', $lists)); $i++) {
                foreach ($lists as $list) {
                    $c = $list[$i] ?? null;
                    if ($c === null || isset($seen[$c['key']]) || $accepted >= $stepEnd || $tried >= $target * 8
                        || (($perQuery[$c['query']] ?? 0) >= $cap && !isset($prefer[$c['key']]))) {
                        continue;
                    }
                    $consider($c);
                }
            }
        }
    }
    // Shapes are a preference: what the searches did not give in one shape, another fills.
    foreach ($spares as [$c, $look]) {
        if ($accepted >= $target) {
            break;
        }
        $image = demo_open($look['original'], $trim);
        if ($image !== null && $accept($c, $image, $look)) {
            $notes['shape'] = 'shape mix filled from spares';
        }
    }
    if (!$searched) {
        $notes['chosen'] = 'all chosen by id in review: no search ran, so none refused this run';
    }
    $counts[$category] = ['target' => $target, 'accepted' => $accepted, 'rejected' => $refusedHere, 'missing' => max(0, $target - $accepted), 'note' => implode('; ', $notes)];
}

demo_write_credits($out, $credits);
demo_write_rejected($out, $rejected, $removed);
demo_write_review($out, $credits, $counts, $extra);
file_put_contents($cache . '/state.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
demo_print_counts($counts);
