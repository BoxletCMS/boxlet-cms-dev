<?php

/**
 * BOXLETCMS.ORG, MORE TO READ (PLAN.md D-246, the owner): after the SEO analysis of 2026-10-13,
 * the home page and the thin pages get what a reader came for, and three pages are new: Boxlet vs
 * WordPress, a website without a cookie banner, and the changelog. Run once, through the site's
 * own code, as four-scripts.php was:
 *
 *   php tools/site/content-1.php <site root> <site address> <folder of the new pictures>
 *
 * Existing pages get new sections INSERTED before their last section (the way on: Next, Download,
 * Not sure yet?), or after the last one on a Docs page, which ends where its words end. A page with
 * a draft is left and said; a page that already has the first new heading is left as done, so a
 * second run changes nothing. New pages are made only where their address is free.
 */

use App\Core\BlockOptions;
use App\Modules\Admin\Activity;
use App\Modules\Demo\DemoPictures;
use App\Modules\Media\MediaMeta;
use App\Modules\Menus\Menu;
use App\Modules\Pages\BlockForm;
use App\Modules\Pages\Page;
use App\Modules\Pages\PageBlocks;
use App\Modules\Pages\PageDraft;
use App\Modules\Pages\PageLinks;
use App\Modules\Pages\PageSeo;
use App\Modules\Pages\SectionForm;
use App\Modules\Design\SectionStyle;
use App\Modules\Pages\Sitemap;
use App\Support\PageCache;
use App\Support\RichText;

if (PHP_SAPI !== 'cli' || $argc !== 4) {
    fwrite(STDERR, "Usage: php tools/site/content-1.php <site root> <site address> <folder of the new pictures>\n");
    exit(1);
}
[, $site, $address, $shots] = $argv;
$site = rtrim((string) realpath($site), '/');
$shots = rtrim((string) realpath($shots), '/');
$origin = parse_url($address);
if (!is_file($site . '/app/bootstrap.php') || !is_array($origin) || !isset($origin['host']) || !is_dir($shots)) {
    fwrite(STDERR, "Not a Boxlet install, not a full site address, or no folder of pictures.\n");
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
$config = $container->get('config');
$locale = (string) ($db->one('SELECT code FROM locales WHERE is_primary = 1')['code'] ?? '');

const DEMO = 'https://demo.boxletcms.org';
const GITHUB = 'https://github.com/BoxletCMS/boxlet-cms-dev';
const RELEASES = 'https://github.com/BoxletCMS/Boxlet-CMS/releases';

// ---- the words' building blocks: tools/site/pages.php's ----------------------------------
$p = static fn (string ...$paragraphs): string => implode('', array_map(static fn (string $s): string => '<p>' . $s . '</p>', $paragraphs));
$ul = static fn (string ...$items): string => '<ul>' . implode('', array_map(static fn (string $s): string => '<li><p>' . $s . '</p></li>', $items)) . '</ul>';
$one = static fn (array $block, array $style = []): array => ['style' => $style, 'blocks' => [$block]];
$hero = static fn (string $h1, string $sub): array => ['hero', ['heading' => $h1, 'subheading' => $sub, 'cta' => null], '', ['height' => 'auto']];
$text = static fn (string $h2, string $body): array => ['text', ['heading' => $h2, 'body' => $body], '', []];
$shot = static fn (string $file, string $h2, string $body, string $layout, ?array $link = null): array => ['image_text', ['heading' => $h2, 'body' => $body, 'image' => 'pic:' . $file, 'image_fit' => 'contain', 'link' => $link], $layout, []];
$questions = static fn (string $h2, array $qa): array => ['accordion', ['heading' => $h2, 'items' => array_map(static fn (array $q): array => ['question' => $q[0], 'answer' => '<p>' . $q[1] . '</p>'], $qa)], '', []];
$cta = static fn (string $h, string $body, string $label, string $url, string $layout = ''): array => ['cta', ['heading' => $h, 'body' => $body, 'action' => ['label' => $label, 'url' => $url]], $layout, []];
$cards = static fn (string $h2, array $items): array => ['cards', ['heading' => $h2, 'items' => array_map(static fn (array $c): array => ['heading' => $c[0], 'body' => '<p>' . $c[1] . '</p>'], $items)], 'grid', ['per_row' => '2']];

require __DIR__ . '/content-1-words.php';

// ---- pictures: the library's by name, the new ones uploaded once -------------------------
$app = (array) $config->get('app', []);
$store = DemoPictures::importer($db, (string) ($app['storage_path'] ?? $site . '/storage'), (string) ($app['public_path'] ?? $site . '/public'));
$picture = static function (string $file) use ($db, $store, $shots, $locale, $newPictures): int {
    $name = (string) preg_replace('~\.[a-z]+$~', '', $file);
    $row = $db->one('SELECT id FROM media WHERE filename = ? ORDER BY id', [$name]);
    if ($row !== null) {
        return (int) $row['id'];
    }
    if (!isset($newPictures[$file]) || !is_file($shots . '/' . $file)) {
        throw new RuntimeException("No picture {$file}");
    }
    $id = $store($shots . '/' . $file, $file);
    MediaMeta::save($db, $id, $locale, $newPictures[$file], '');
    echo "picture {$file}: uploaded\n";

    return $id;
};

// ---- addresses -----------------------------------------------------------------------------
$pageAt = static function (string $path) use ($db, $locale): ?array {
    $parent = null;
    $page = null;
    foreach ($path === '' ? [''] : explode('/', $path) as $slug) {
        $page = $db->one(
            'SELECT id, title, slug, parent_id, status, seo_json FROM pages WHERE locale = ? AND slug = ? AND ' . ($parent === null ? 'parent_id IS NULL' : 'parent_id = ?'),
            $parent === null ? [$locale, $slug] : [$locale, $slug, $parent],
        );
        if ($page === null) {
            return null;
        }
        $parent = (int) $page['id'];
    }

    return $page;
};
$reference = static function (string $path) use ($pageAt): string {
    $page = $pageAt($path) ?? throw new RuntimeException("No page {$path}");

    return PageLinks::to((int) $page['id']);
};
// Markers to ids and references, through the content's own fields, repeaters included (seed.php).
$resolve = static function (array $fields, array $content) use (&$resolve, $reference, $picture): array {
    foreach ($fields as $name => $field) {
        $value = $content[$name] ?? null;
        if ($field['type'] === 'media' && is_string($value) && str_starts_with($value, 'pic:')) {
            $content[$name] = $picture(substr($value, 4));
        } elseif ($field['type'] === 'link' && is_array($value) && is_string($value['url'] ?? null) && str_starts_with($value['url'], 'site:')) {
            $content[$name]['url'] = $reference(substr($value['url'], 5));
        } elseif ($field['type'] === 'richtext' && is_string($value)) {
            $content[$name] = RichText::sanitize((string) preg_replace_callback('~(?<=href=")site:([a-z0-9/-]*)(?=")~', static fn (array $m): string => $reference($m[1]), $value));
        } elseif ($field['type'] === 'repeater' && is_array($value)) {
            foreach ($value as $i => $item) {
                $content[$name][$i] = $resolve($field['fields'], $item);
            }
        }
    }

    return $content;
};
/** New sections as the editor writes them: keys of their own, after any the page has. */
$rows = static function (array $sections, int $from) use ($registry, $resolve): array {
    $out = [[], []];
    foreach ($sections as $at => $section) {
        $key = SectionForm::key(null, $from + $at);
        $out[0][] = ['key' => $key, 'id' => null, 'layout' => 'one', 'stack' => null, 'style' => SectionStyle::normalize($section['style'])];
        foreach ($section['blocks'] as $n => [$type, $content, $layout, $options]) {
            $out[1][] = [
                'key' => BlockForm::key(null, ($from + $at) * 10 + $n),
                'id' => null,
                'type' => $type,
                'content' => $registry->normalize($type, $resolve($registry->get($type)['fields'], $content)),
                'style' => SectionStyle::normalize([]),
                'options' => $options === [] ? [] : BlockOptions::normalize($registry->get($type)['options'], $options),
                'layout' => $registry->own($type, $layout),
                'section' => $key,
                'column' => 0,
            ];
        }
    }

    return $out;
};
$headings = static function (int $pageId) use ($db, $registry): string {
    return implode("\n", array_map(static fn (array $b): string => (string) ($b['content']['heading'] ?? ''), PageBlocks::editable($db, $registry, $pageId)));
};

// ---- new pages, first: the home page's questions and the Download page link to them ------------
foreach ($newPages as $page) {
    if ($pageAt($page['path']) !== null) {
        echo "/{$page['path']}: there already, left\n";
        continue;
    }
    $problem = App\Modules\Pages\Slug::problem($db, $locale, $page['path'], null);
    if ($problem !== null) {
        echo "/{$page['path']}: {$problem}, left\n";
        continue;
    }
    $id = Page::create($db, $registry, $locale, $page['title'], $page['path'], null, []);
    [$sections, $blocks] = $rows($page['sections'], 0);
    Page::update($db, $registry, $id, [
        'title' => $page['title'],
        'slug' => $page['path'],
        'parent_id' => null,
        'status' => 'draft',
        'seo_json' => PageSeo::json(['title' => $page['seo_title'], 'description' => $page['description']]),
    ], $blocks, $sections);
    Page::setStatus($db, $id, true);
    Activity::record($db, 'page', 'created', $id, $page['title']);
    echo "/{$page['path']}: made and published\n";
}

// ---- existing pages: sections inserted -------------------------------------------------------
foreach ($additions as $path => [$where, $sections]) {
    $page = $pageAt($path);
    $label = '/' . $path;
    if ($page === null) {
        echo "{$label}: no such page, left\n";
        continue;
    }
    $first = (string) ($sections[0]['blocks'][0][1]['heading'] ?? '');
    if ($first !== '' && str_contains($headings((int) $page['id']), $first)) {
        echo "{$label}: already has “{$first}”, left\n";
        continue;
    }
    if (PageDraft::exists($db, (int) $page['id'])) {
        echo "{$label}: has a draft, left alone\n";
        continue;
    }
    $blocks = PageBlocks::editable($db, $registry, (int) $page['id']);
    $kept = PageBlocks::editableSections($db, (int) $page['id']);
    [$newSections, $newBlocks] = $rows($sections, 100);
    $at = $where === 'end' ? count($kept) : count($kept) - 1;
    array_splice($kept, $at, 0, $newSections);
    Page::update($db, $registry, (int) $page['id'], [
        'title' => (string) $page['title'],
        'slug' => (string) $page['slug'],
        'parent_id' => $page['parent_id'] === null ? null : (int) $page['parent_id'],
        'status' => (string) $page['status'],
        'seo_json' => (string) $page['seo_json'],
    ], array_merge($blocks, $newBlocks), $kept);
    Activity::record($db, 'page', 'saved', (int) $page['id'], (string) $page['title']);
    echo "{$label}: " . count($sections) . " sections added\n";
}

// ---- a fact the site had wrong ----------------------------------------------------------------
// The privacy paragraph is written in English and Croatian, the languages Boxlet has it in.
$privacy = $pageAt('features/privacy');
if ($privacy !== null && !PageDraft::exists($db, (int) $privacy['id'])) {
    $blocks = PageBlocks::editable($db, $registry, (int) $privacy['id']);
    $changed = false;
    foreach ($blocks as $i => $block) {
        $body = $block['content']['body'] ?? null;
        if (is_string($body) && str_contains($body, 'in every language the site speaks')) {
            $blocks[$i]['content']['body'] = str_replace('in every language the site speaks', 'in English or Croatian', $body);
            $changed = true;
        }
    }
    if ($changed) {
        Page::update($db, $registry, (int) $privacy['id'], [
            'title' => (string) $privacy['title'], 'slug' => (string) $privacy['slug'],
            'parent_id' => $privacy['parent_id'] === null ? null : (int) $privacy['parent_id'],
            'status' => (string) $privacy['status'], 'seo_json' => (string) $privacy['seo_json'],
        ], $blocks, PageBlocks::editableSections($db, (int) $privacy['id']));
        echo "/features/privacy: the privacy paragraph's languages said as they are\n";
    }
}

// Languages: the visitors' words in Portuguese since rc.9 (D-240), no longer Slovenian.
$languages = $pageAt('features/languages');
if ($languages !== null && !PageDraft::exists($db, (int) $languages['id'])) {
    $blocks = PageBlocks::editable($db, $registry, (int) $languages['id']);
    $changed = false;
    foreach ($blocks as $i => $block) {
        $body = $block['content']['body'] ?? null;
        if (is_string($body) && str_contains($body, 'Italian and Slovenian')) {
            $blocks[$i]['content']['body'] = str_replace('Italian and Slovenian', 'Italian and Portuguese', $body);
            $changed = true;
        }
    }
    if ($changed) {
        Page::update($db, $registry, (int) $languages['id'], [
            'title' => (string) $languages['title'], 'slug' => (string) $languages['slug'],
            'parent_id' => $languages['parent_id'] === null ? null : (int) $languages['parent_id'],
            'status' => (string) $languages['status'], 'seo_json' => (string) $languages['seo_json'],
        ], $blocks, PageBlocks::editableSections($db, (int) $languages['id']));
        echo "/features/languages: Portuguese, not Slovenian\n";
    }
}

// ---- the footer's menus: the new pages, once ---------------------------------------------------
foreach ($menuItems as [$menuName, $label, $path]) {
    $menu = $db->one('SELECT id FROM menus WHERE locale = ? AND name = ?', [$locale, $menuName]);
    $page = $pageAt($path);
    if ($menu === null || $page === null) {
        echo "menu {$menuName}: no menu or no page for {$label}, left\n";
        continue;
    }
    if ($db->one('SELECT id FROM menu_items WHERE menu_id = ? AND page_id = ?', [(int) $menu['id'], (int) $page['id']]) !== null) {
        echo "menu {$menuName}: {$label} there already\n";
        continue;
    }
    Menu::addItem($db, (int) $menu['id'], null, (int) $page['id'], null, $label);
    echo "menu {$menuName}: {$label} added\n";
}

// What any admin change does: the sitemap and llms.txt, the kept pages.
Sitemap::refresh($container);
PageCache::clear();
echo "Sitemap, robots.txt and llms.txt written; kept pages emptied.\n";
