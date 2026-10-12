<?php

/**
 * BOXLETCMS.ORG CALLS IT "BOXLET CMS" WHERE IT IS MET FIRST (PLAN.md D-232, the owner): the
 * site's name, the footer's first column and small print, the home page's search title, and
 * the first "Boxlet" on the pages that name it early. Everywhere else it stays Boxlet. Run once
 * against the live site, through its own code, as seed.php and contact.php were.
 *
 *   php tools/site/naming.php <site root> <site address>
 *
 * Each change is a sentence as the seed wrote it, changed once on its page; a page whose
 * words are no longer that sentence is left as it is and said, and so is a page with a draft:
 * the owner's work is not written over.
 */

use App\Core\Settings;
use App\Modules\Admin\Activity;
use App\Modules\Pages\Page;
use App\Modules\Pages\PageBlocks;
use App\Modules\Pages\PageDraft;
use App\Modules\Pages\Sitemap;
use App\Modules\Settings\SiteChrome;
use App\Support\PageCache;

/** path => [what the seed wrote, what it says now], the first of it on the page. */
const CHANGES = [
    '' => ['Boxlet sets no cookies for visitors', 'Boxlet CMS sets no cookies for visitors'],
    'why' => ['Boxlet does the handful of things', 'Boxlet CMS does the handful of things'],
    'features' => ['Boxlet does a short list of things and tries', 'Boxlet CMS does a short list of things and tries'],
    'features/privacy' => ['Boxlet doesn\'t set a single cookie', 'Boxlet CMS doesn\'t set a single cookie'],
    'features/design' => ['the part Boxlet is really about', 'the part Boxlet CMS is really about'],
    'features/pictures' => ['Boxlet makes every size', 'Boxlet CMS makes every size'],
    'get' => ['Download Boxlet', 'Download Boxlet CMS'],
    'contact' => ['Boxlet does a short list of things on purpose', 'Boxlet CMS does a short list of things on purpose'],
];
const HOME_TITLE = ['Boxlet: a small CMS for small websites', 'Boxlet CMS, for people who build lots of small websites'];

if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR, "Usage: php tools/site/naming.php <site root> <site address>\n");
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

// ---- the name, the footer ------------------------------------------------------------------
if (in_array(Settings::get($db, 'site_name', ''), ['Boxlet', 'BoxletCMS'], true)) {
    Settings::set($db, 'site_name', 'Boxlet CMS');
    echo "Site name: Boxlet CMS\n";
}
$footer = SiteChrome::footer($db, $locale);
$header = SiteChrome::header($db, $locale);
$columns = $footer['columns'];
$small = $footer['small_print'];
if (in_array($columns[0]['title'], ['Boxlet', 'BoxletCMS'], true)) {
    $columns[0]['title'] = 'Boxlet CMS';
}
$small = (string) preg_replace('~^© \{\{year\}\} Boxlet(?= ·)~u', '© {{year}} Boxlet CMS', $small);
if ($columns !== $footer['columns'] || $small !== $footer['small_print']) {
    SiteChrome::saveForLocale($db, $locale, ['button_label' => $header['button']['label'], 'button_url' => $header['button']['url'], 'columns' => $columns, 'small_print' => $small]);
    Activity::record($db, 'design', 'header_saved', null, '');
    echo "Footer: \"{$columns[0]['title']}\", \"{$small}\"\n";
}

// ---- the pages ---------------------------------------------------------------------------
/** The page at a path of slugs, or null. */
$find = static function (string $path) use ($db, $locale): ?array {
    $parent = null;
    $row = null;
    foreach ($path === '' ? [''] : explode('/', $path) as $slug) {
        $row = $parent === null
            ? $db->one('SELECT id, title, slug, parent_id, status, seo_json FROM pages WHERE locale = ? AND slug = ? AND parent_id IS NULL', [$locale, $slug])
            : $db->one('SELECT id, title, slug, parent_id, status, seo_json FROM pages WHERE locale = ? AND slug = ? AND parent_id = ?', [$locale, $slug, $parent]);
        if ($row === null) {
            return null;
        }
        $parent = (int) $row['id'];
    }

    return $row;
};
/** $value with the first $old in its strings made $new; $done says whether it was found. */
$once = static function (mixed $value, string $old, string $new, bool &$done) use (&$once): mixed {
    if ($done) {
        return $value;
    }
    if (is_string($value) && str_contains($value, $old)) {
        $done = true;

        return preg_replace('~' . preg_quote($old, '~') . '~u', str_replace(['\\', '$'], ['\\\\', '\\$'], $new), $value, 1);
    }
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = $once($item, $old, $new, $done);
        }
    }

    return $value;
};
foreach (CHANGES as $path => [$old, $new]) {
    $page = $find($path);
    if ($page === null) {
        echo "/{$path}: no such page, left\n";
        continue;
    }
    if (PageDraft::exists($db, (int) $page['id'])) {
        echo "/{$path}: has a draft, left alone\n";
        continue;
    }
    $blocks = PageBlocks::editable($db, $registry, (int) $page['id']);
    // Already said: "Download Boxlet" is in "Download Boxlet CMS" too, and a second run made
    // it "Boxlet CMS CMS" (rehearsed on the copy).
    if (str_contains((string) json_encode(array_column($blocks, 'content'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $new)) {
        echo "/{$path}: says \"{$new}\" already, left\n";
        continue;
    }
    $done = false;
    foreach ($blocks as $at => $block) {
        $blocks[$at]['content'] = $once($block['content'], $old, $new, $done);
    }
    // The description says it as the page does: the hero's line, where the page is first met.
    $seo = json_decode((string) $page['seo_json'], true);
    $seo = is_array($seo) ? $seo : [];
    $described = false;
    $seo = $once($seo, $old, $new, $described);
    if ($path === '' && ($seo['title'] ?? '') === HOME_TITLE[0]) {
        $seo['title'] = HOME_TITLE[1];
        $described = true;
    }
    if (!$done && !$described) {
        echo "/{$path}: \"{$old}\" is not on it now, left\n";
        continue;
    }
    Page::update($db, $registry, (int) $page['id'], [
        'title' => (string) $page['title'],
        'slug' => (string) $page['slug'],
        'parent_id' => $page['parent_id'] === null ? null : (int) $page['parent_id'],
        'status' => (string) $page['status'],
        'seo_json' => (string) json_encode($seo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ], $blocks, PageBlocks::editableSections($db, (int) $page['id']));
    Activity::record($db, 'page', 'saved', (int) $page['id'], (string) $page['title']);
    echo "/{$path}: " . ($done ? "\"{$new}\"" : 'its words as they were') . ($described ? ', and its search title or description' : '') . "\n";
}

// What any admin change does: the sitemap and llms.txt (the descriptions), the kept pages.
Sitemap::refresh($container);
PageCache::clear();
echo "Sitemap, robots.txt and llms.txt written; kept pages emptied.\n";
