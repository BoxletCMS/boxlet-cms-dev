<?php

/**
 * WHAT THE BOXLETCMS.ORG CONTENT SCRIPTS SHARE (PLAN.md D-274): the arguments, the site's own code
 * started as a request would, and the helpers content-2.php wrote for changing a page through
 * Page::update, now that content-3.php needs them too. Required by a script after it sets
 * $newPictures; it leaves $db, $registry, $container, $locale, $dry and the helpers behind.
 *
 *   php tools/site/<script> <site root> <site address> <folder of the new pictures> [--dry]
 *
 * With --dry the script runs its changes in one transaction and rolls them back ($finish): no
 * picture is uploaded (an existing one stands in), the sitemap and the kept pages untouched.
 */

use App\Core\BlockOptions;
use App\Modules\Admin\Activity;
use App\Modules\Demo\DemoPictures;
use App\Modules\Design\SectionStyle;
use App\Modules\Media\MediaMeta;
use App\Modules\Pages\BlockForm;
use App\Modules\Pages\Page;
use App\Modules\Pages\PageBlocks;
use App\Modules\Pages\PageDraft;
use App\Modules\Pages\PageLinks;
use App\Modules\Pages\SectionForm;
use App\Modules\Pages\Sitemap;
use App\Support\PageCache;
use App\Support\RichText;

if (PHP_SAPI !== 'cli' || ($argc !== 4 && !($argc === 5 && $argv[4] === '--dry'))) {
    fwrite(STDERR, "Usage: php {$argv[0]} <site root> <site address> <folder of the new pictures> [--dry]\n");
    exit(1);
}
[, $site, $address, $shots] = $argv;
$dry = ($argv[4] ?? '') === '--dry';
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
$container->get('router');
$db = $container->get('db');
$registry = $container->get('blocks');
$config = $container->get('config');
$locale = (string) ($db->one('SELECT code FROM locales WHERE is_primary = 1')['code'] ?? '');

// ---- pictures, pages and references: content-1.php's ---------------------------------------
// The caller names its new pictures, with their alt text, in $newPictures before this file.
$app = (array) $config->get('app', []);
$store = DemoPictures::importer($db, (string) ($app['storage_path'] ?? $site . '/storage'), (string) ($app['public_path'] ?? $site . '/public'));
$picture = static function (string $file) use ($db, $store, $shots, $locale, $newPictures, $dry): int {
    $name = (string) preg_replace('~\.[a-z]+$~', '', $file);
    $row = $db->one('SELECT id FROM media WHERE filename = ? ORDER BY id', [$name]);
    if ($row !== null) {
        return (int) $row['id'];
    }
    if ($dry) {
        echo "picture {$file}: would be uploaded (a stand-in for the rehearsal)\n";

        return (int) ($db->one('SELECT MIN(id) AS id FROM media')['id'] ?? 0);
    }
    if (!isset($newPictures[$file]) || !is_file($shots . '/' . $file)) {
        throw new RuntimeException("No picture {$file}");
    }
    $id = $store($shots . '/' . $file, $file);
    MediaMeta::save($db, $id, $locale, $newPictures[$file], '');
    echo "picture {$file}: uploaded\n";

    return $id;
};
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
$rows = static function (array $sections, int $from) use ($registry, $resolve): array {
    $out = [[], []];
    foreach ($sections as $at => $section) {
        $key = SectionForm::key(null, $from + $at);
        $out[0][] = ['key' => $key, 'id' => null, 'layout' => 'one', 'stack' => null, 'style' => SectionStyle::normalize($section['style'] ?? [])];
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

/**
 * One page changed through $change, which gets its blocks and sections and gives them back with
 * what is new; saved only when something is, and never over a draft.
 */
$page = static function (string $path, Closure $change) use ($db, $registry, $pageAt): void {
    $label = '/' . $path;
    $page = $pageAt($path);
    if ($page === null) {
        echo "{$label}: no such page, left\n";
        return;
    }
    if (PageDraft::exists($db, (int) $page['id'])) {
        echo "{$label}: has a draft, left alone\n";
        return;
    }
    $blocks = PageBlocks::editable($db, $registry, (int) $page['id']);
    $sections = PageBlocks::editableSections($db, (int) $page['id']);
    [$newBlocks, $newSections, $said] = $change($blocks, $sections);
    if ($said === []) {
        echo "{$label}: nothing to change\n";
        return;
    }
    Page::update($db, $registry, (int) $page['id'], [
        'title' => (string) $page['title'],
        'slug' => (string) $page['slug'],
        'parent_id' => $page['parent_id'] === null ? null : (int) $page['parent_id'],
        'status' => (string) $page['status'],
        'seo_json' => (string) $page['seo_json'],
    ], $newBlocks, $newSections);
    Activity::record($db, 'page', 'saved', (int) $page['id'], (string) $page['title']);
    echo "{$label}: " . implode('; ', $said) . "\n";
};

/** Where a block whose heading is $heading stands: its section's place, or null. */
$sectionOf = static function (array $blocks, array $sections, string $heading): ?int {
    foreach ($blocks as $block) {
        if (($block['content']['heading'] ?? null) === $heading) {
            foreach ($sections as $at => $section) {
                if ($section['key'] === $block['section']) {
                    return $at;
                }
            }
        }
    }

    return null;
};
/** Whether any block's heading is $heading already: a section added on an earlier run. */
$has = static fn (array $blocks, string $heading): bool => in_array($heading, array_map(static fn (array $b): string => (string) ($b['content']['heading'] ?? ''), $blocks), true);
/** New sections put after the section at $at. */
$insert = static function (array $blocks, array $sections, int $at, array $new) use ($rows): array {
    [$newSections, $newBlocks] = $rows($new, 100 + count($sections));
    array_splice($sections, $at + 1, 0, $newSections);

    return [array_merge($blocks, $newBlocks), $sections];
};

/** Commits or, rehearsing, rolls back; then what any admin change does. */
$finish = static function () use ($db, $container, $dry): void {
    if ($dry) {
        $db->pdo()->rollBack();
        echo "Rehearsal: every change rolled back.\n";

        return;
    }
    Sitemap::refresh($container);
    PageCache::clear();
    echo "Sitemap, robots.txt and llms.txt written; kept pages emptied.\n";
};
if ($dry) {
    $db->pdo()->beginTransaction();
}
