<?php

/**
 * BOXLETCMS.ORG COUNTS FOUR SCRIPTS (PLAN.md D-239): rc.8 added site-lightbox.js (D-236), so
 * Home's "3 · small scripts, at most" and Privacy's "Three small scripts at most" say four.
 * Run once against the live site, through its own code, as naming.php was.
 *
 *   php tools/site/four-scripts.php <site root> <site address>
 *
 * Each is changed only where it still stands as the seed wrote it; a page with a draft is left
 * and said: the owner's work is not written over.
 */

use App\Modules\Admin\Activity;
use App\Modules\Pages\Page;
use App\Modules\Pages\PageBlocks;
use App\Modules\Pages\PageDraft;
use App\Modules\Pages\Sitemap;
use App\Support\PageCache;

if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR, "Usage: php tools/site/four-scripts.php <site root> <site address>\n");
    exit(1);
}
[, $site, $address] = $argv;
$site = rtrim((string) realpath($site), '/');
$origin = parse_url($address);
if (!is_file($site . '/app/bootstrap.php') || !is_array($origin) || !isset($origin['host'])) {
    fwrite(STDERR, "Not a Boxlet install, or not a full site address.\n");
    exit(1);
}
$https = ($origin['scheme'] ?? '') === 'https';
chdir($site);
$_SERVER += ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php', 'SERVER_NAME' => $origin['host'], 'SERVER_PORT' => (string) ($origin['port'] ?? ($https ? 443 : 80)), 'HTTPS' => $https ? 'on' : 'off', 'REMOTE_ADDR' => '127.0.0.1'];
require $site . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($site)->safeLoad();
/** @var App\Core\Container $container */
$container = require $site . '/app/bootstrap.php';
// Addresses are configured where a request builds its router (seed.php, O-63).
$container->get('router');
$db = $container->get('db');
$registry = $container->get('blocks');
$locale = (string) ($db->one('SELECT code FROM locales WHERE is_primary = 1')['code'] ?? '');

/**
 * One page's blocks changed by $change, saved as they were otherwise; said either way.
 *
 * @param Closure(array<int, array<string, mixed>>): ?array<int, array<string, mixed>> $change the blocks changed, or null when nothing was
 */
$edit = static function (string $label, ?array $page, Closure $change) use ($db, $registry): void {
    if ($page === null) {
        echo "{$label}: no such page, left\n";

        return;
    }
    if (PageDraft::exists($db, (int) $page['id'])) {
        echo "{$label}: has a draft, left alone\n";

        return;
    }
    $blocks = $change(PageBlocks::editable($db, $registry, (int) $page['id']));
    if ($blocks === null) {
        echo "{$label}: not as the seed wrote it, left\n";

        return;
    }
    Page::update($db, $registry, (int) $page['id'], [
        'title' => (string) $page['title'],
        'slug' => (string) $page['slug'],
        'parent_id' => $page['parent_id'] === null ? null : (int) $page['parent_id'],
        'status' => (string) $page['status'],
        'seo_json' => (string) $page['seo_json'],
    ], $blocks, PageBlocks::editableSections($db, (int) $page['id']));
    Activity::record($db, 'page', 'saved', (int) $page['id'], (string) $page['title']);
    echo "{$label}: four\n";
};
$columns = 'id, title, slug, parent_id, status, seo_json';

// Home: the number above "small scripts, at most".
$edit('/ (the number)', $db->one("SELECT {$columns} FROM pages WHERE locale = ? AND slug = '' AND parent_id IS NULL", [$locale]), static function (array $blocks): ?array {
    foreach ($blocks as $at => $block) {
        foreach ($block['type'] === 'stats' ? ($block['content']['items'] ?? []) : [] as $i => $item) {
            if (($item['label'] ?? '') === 'small scripts, at most' && ($item['value'] ?? '') === '3') {
                $blocks[$at]['content']['items'][$i]['value'] = '4';

                return $blocks;
            }
        }
    }

    return null;
});

// Privacy: the list under "What else stays quiet".
$features = $db->one("SELECT id FROM pages WHERE locale = ? AND slug = 'features' AND parent_id IS NULL", [$locale]);
$privacy = $features === null ? null : $db->one("SELECT {$columns} FROM pages WHERE locale = ? AND slug = 'privacy' AND parent_id = ?", [$locale, (int) $features['id']]);
$edit('/features/privacy (the list)', $privacy, static function (array $blocks): ?array {
    foreach ($blocks as $at => $block) {
        $body = $block['content']['body'] ?? null;
        if ($block['type'] === 'text' && is_string($body) && str_contains($body, 'Three small scripts at most')) {
            $blocks[$at]['content']['body'] = str_replace('Three small scripts at most', 'Four small scripts at most', $body);

            return $blocks;
        }
    }

    return null;
});

// What any admin change does: the sitemap and llms.txt, the kept pages.
Sitemap::refresh($container);
PageCache::clear();
echo "Sitemap, robots.txt and llms.txt written; kept pages emptied.\n";
