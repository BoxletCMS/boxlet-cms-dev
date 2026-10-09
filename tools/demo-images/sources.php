<?php

/*
 * THE FOUR SOURCES, each turned into one shape of candidate:
 *
 *   key, source, title, author, author_url, source_url, license, license_url,
 *   image_url (what is downloaded), width, height (the original's), text (its words and tags,
 *   for the rules), alt (what the source says the picture shows, where it says anything).
 *
 * Pixabay needs PIXABAY_API_KEY, Pexels PEXELS_API_KEY; the others need no key. Only what each
 * source marks as free is asked for: The Met's isPublicDomain with a primaryImage, AIC's
 * is_public_domain, Openverse's license=cc0 (and nothing else: never pdm, never by).
 */

const CC0_URL = 'https://creativecommons.org/publicdomain/zero/1.0/';

/** A source's words as plain text: Flickr's titles arrive with their HTML. */
function demo_plain(mixed $text): string
{
    return trim((string) preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/**
 * One page of one search of one source.
 *
 * @param array<string, mixed> $env
 * @return list<array<string, mixed>>|null null when the source cannot be asked (no key)
 */
function demo_search(string $source, string $query, int $page, array $env, string $orientation = ''): ?array
{
    return match ($source) {
        'pixabay' => demo_pixabay($query, $page, $env, $orientation),
        'pexels' => demo_pexels($query, $page, $env),
        'met' => demo_met($query, $page, $env),
        'aic' => demo_aic($query, $page, $env),
        'openverse' => demo_openverse($query, $page, $env),
        default => [],
    };
}

/**
 * Pixabay (the Pixabay Content License). Its answers are kept 24 hours, as its terms ask, and
 * its pictures downloaded rather than linked, which they forbid. A key without full access gets
 * largeImageURL, 1280 px on the longer side at most: that is the floor for these, recorded as
 * max_source_px, and such a picture is never a hero.
 *
 * @return list<array<string, mixed>>|null
 */
function demo_pixabay(string $query, int $page, array $env, string $orientation, int $id = 0): ?array
{
    if (($env['pixabay_key'] ?? '') === '') {
        return null;
    }
    // One picture by its id (a choice made in review), or a page of a search.
    $url = 'https://pixabay.com/api/?' . http_build_query(array_filter($id > 0 ? ['key' => $env['pixabay_key'], 'id' => $id] : [
        'key' => $env['pixabay_key'], 'q' => $query, 'image_type' => 'photo', 'safesearch' => 'true',
        'per_page' => 50, 'order' => 'popular', 'page' => $page, 'orientation' => $orientation,
    ], static fn ($v): bool => $v !== ''));
    $data = demo_json($url, $env['cache'], [], $env['ua'], 86400);
    $found = [];
    foreach ((array) ($data['hits'] ?? []) as $p) {
        $full = (string) ($p['imageURL'] ?? $p['fullHDURL'] ?? '');
        $tags = demo_plain($p['tags'] ?? '');
        $found[] = [
            'key' => 'pixabay:' . $p['id'],
            'source' => 'Pixabay',
            'title' => $tags,
            'author' => demo_plain($p['user'] ?? ''),
            'author_url' => 'https://pixabay.com/users/' . rawurlencode((string) ($p['user'] ?? '')) . '-' . (int) ($p['user_id'] ?? 0) . '/',
            'source_url' => (string) $p['pageURL'],
            'license' => 'Pixabay Content License',
            'license_url' => 'https://pixabay.com/service/license-summary/',
            'image_url' => $full !== '' ? $full : (string) $p['largeImageURL'],
            'width' => (int) $p['imageWidth'],
            'height' => (int) $p['imageHeight'],
            'max_source_px' => $full !== '' ? 0 : 1280,
            'text' => $tags . ' ' . (string) ($p['type'] ?? ''),
            'ai' => (bool) ($p['isAiGenerated'] ?? false),
            'low_quality' => (bool) ($p['isLowQuality'] ?? false),
            'alt' => '',
        ];
    }

    return $found;
}

/** @return list<array<string, mixed>>|null */
function demo_pexels(string $query, int $page, array $env): ?array
{
    if (($env['pexels_key'] ?? '') === '') {
        return null;
    }
    $url = 'https://api.pexels.com/v1/search?' . http_build_query(['query' => $query, 'per_page' => 40, 'page' => $page]);
    $data = demo_json($url, $env['cache'], ['Authorization' => $env['pexels_key']], $env['ua']);
    $found = [];
    foreach ((array) ($data['photos'] ?? []) as $p) {
        $w = (int) $p['width'];
        $h = (int) $p['height'];
        $found[] = [
            'key' => 'pexels:' . $p['id'],
            'source' => 'Pexels',
            'title' => demo_plain(($p['alt'] ?? '')),
            'author' => (string) ($p['photographer'] ?? ''),
            'author_url' => (string) ($p['photographer_url'] ?? ''),
            'source_url' => (string) $p['url'],
            'license' => 'Pexels License',
            'license_url' => 'https://www.pexels.com/license/',
            // Asked for a little over 2000 on its longer side: the original can be 10 MB.
            'image_url' => $p['src']['original'] . '?auto=compress&cs=tinysrgb&' . ($w >= $h ? 'w=2400' : 'h=2400'),
            'width' => $w,
            'height' => $h,
            'text' => (string) ($p['alt'] ?? '') . ' ' . (string) $p['url'],
            'alt' => demo_plain(($p['alt'] ?? '')),
        ];
    }

    return $found;
}

/** @return list<array<string, mixed>> */
function demo_met(string $query, int $page, array $env): array
{
    $url = 'https://collectionapi.metmuseum.org/public/collection/v1.1/search?' . http_build_query(['q' => $query, 'hasImages' => 'true', 'limit' => 40, 'offset' => ($page - 1) * 40]);
    $ids = (array) (demo_json($url, $env['cache'], [], $env['ua'])['objectIDs'] ?? []);
    $found = [];
    foreach ($ids as $id) {
        $one = demo_met_one((int) $id, $env);
        if ($one !== null) {
            $found[] = $one;
        }
    }

    return $found;
}

/**
 * One Met object as a candidate, by its id; null unless it is public domain with a picture.
 *
 * @return array<string, mixed>|null
 */
function demo_met_one(int $id, array $env): ?array
{
    $o = demo_json('https://collectionapi.metmuseum.org/public/collection/v1/objects/' . $id, $env['cache'], [], $env['ua']);
    if ($o === null || ($o['isPublicDomain'] ?? false) !== true || ($o['primaryImage'] ?? '') === '') {
        return null;
    }
    $tags = implode(' ', array_map(static fn ($t): string => (string) ($t['term'] ?? ''), (array) ($o['tags'] ?? [])));

    return [
        'key' => 'met:' . $o['objectID'],
        'source' => 'The Met',
        'title' => demo_plain((string) $o['title'] . ((string) ($o['objectDate'] ?? '') !== '' ? ', ' . $o['objectDate'] : '')),
        'author' => (string) ($o['artistDisplayName'] ?? '') !== '' ? (string) $o['artistDisplayName'] : 'Unknown',
        'author_url' => (string) ($o['artistWikidata_URL'] ?? '') !== '' ? (string) $o['artistWikidata_URL'] : (string) ($o['artistULAN_URL'] ?? ''),
        'source_url' => (string) $o['objectURL'],
        'license' => 'CC0 1.0',
        'license_url' => CC0_URL,
        'image_url' => (string) $o['primaryImage'],
        // Not given: known once the file is here.
        'width' => 0,
        'height' => 0,
        'text' => implode(' ', [(string) $o['title'], (string) ($o['medium'] ?? ''), (string) ($o['classification'] ?? ''), (string) ($o['department'] ?? ''), $tags]),
        'kind' => (string) ($o['classification'] ?? '') . ' ' . (string) ($o['objectName'] ?? ''),
        'subject' => (string) $o['title'] . ' ' . $tags,
        'alt' => demo_plain($o['title']),
    ];
}

/** @return list<array<string, mixed>> */
function demo_aic(string $query, int $page, array $env): array
{
    $url = 'https://api.artic.edu/api/v1/artworks/search?' . http_build_query([
        'q' => $query,
        'query' => ['term' => ['is_public_domain' => 'true']],
        'fields' => 'id,title,image_id,artist_title,artist_id,thumbnail,date_display,classification_title,medium_display,term_titles,subject_titles,is_public_domain',
        'limit' => 40,
        'page' => $page,
    ]);
    $data = demo_json($url, $env['cache'], ['AIC-User-Agent' => $env['ua']], $env['ua']);
    $found = [];
    foreach ((array) ($data['data'] ?? []) as $a) {
        if (($a['is_public_domain'] ?? false) !== true || ($a['image_id'] ?? '') === '') {
            continue;
        }
        $found[] = [
            'key' => 'aic:' . $a['id'],
            'source' => 'Art Institute of Chicago',
            'title' => demo_plain((string) $a['title'] . ((string) ($a['date_display'] ?? '') !== '' ? ', ' . $a['date_display'] : '')),
            'author' => (string) ($a['artist_title'] ?? '') !== '' ? (string) $a['artist_title'] : 'Unknown',
            'author_url' => isset($a['artist_id']) ? 'https://www.artic.edu/artists/' . (int) $a['artist_id'] : '',
            'source_url' => 'https://www.artic.edu/artworks/' . (int) $a['id'],
            'license' => 'CC0 1.0',
            'license_url' => CC0_URL,
            'image_url' => 'https://www.artic.edu/iiif/2/' . $a['image_id'] . '/full/1686,/0/default.jpg',
            'width' => (int) ($a['thumbnail']['width'] ?? 0),
            'height' => (int) ($a['thumbnail']['height'] ?? 0),
            'text' => implode(' ', [(string) $a['title'], (string) ($a['classification_title'] ?? ''), (string) ($a['medium_display'] ?? ''), implode(' ', (array) ($a['term_titles'] ?? [])), implode(' ', (array) ($a['subject_titles'] ?? []))]),
            'kind' => (string) ($a['classification_title'] ?? ''),
            'alt' => demo_plain($a['thumbnail']['alt_text'] ?? $a['title']),
        ];
    }

    return $found;
}

/** @return list<array<string, mixed>> */
function demo_openverse(string $query, int $page, array $env): array
{
    $url = 'https://api.openverse.org/v1/images/?' . http_build_query(['q' => $query, 'license' => 'cc0', 'size' => 'large', 'excluded_source' => 'rawpixel', 'page_size' => 20, 'page' => $page]);
    $data = demo_json($url, $env['cache'], [], $env['ua']);
    $found = [];
    foreach ((array) ($data['results'] ?? []) as $r) {
        $tags = implode(' ', array_map(static fn ($t): string => (string) ($t['name'] ?? ''), (array) ($r['tags'] ?? [])));
        $found[] = [
            'key' => 'openverse:' . $r['id'],
            'source' => 'Openverse/' . (string) ($r['source'] ?? $r['provider'] ?? ''),
            'title' => demo_plain(($r['title'] ?? '')),
            'author' => demo_plain(($r['creator'] ?? '')),
            'author_url' => (string) ($r['creator_url'] ?? ''),
            'source_url' => (string) ($r['foreign_landing_url'] ?? ''),
            'license' => ($r['license'] ?? '') === 'cc0' ? 'CC0 1.0' : (string) ($r['license'] ?? ''),
            'license_url' => (string) ($r['license_url'] ?? ''),
            'license_raw' => (string) ($r['license'] ?? '') . ' ' . (string) ($r['license_version'] ?? ''),
            'image_url' => (string) ($r['url'] ?? ''),
            'width' => (int) ($r['width'] ?? 0),
            'height' => (int) ($r['height'] ?? 0),
            'text' => (string) ($r['title'] ?? '') . ' ' . $tags,
            'creator' => demo_plain(($r['creator'] ?? '')),
            'subject' => demo_plain(($r['title'] ?? '')) . ' ' . $tags,
            'provider' => (string) ($r['provider'] ?? ''),
            'alt' => demo_plain(($r['title'] ?? '')),
        ];
    }

    return $found;
}
