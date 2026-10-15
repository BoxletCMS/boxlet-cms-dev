<?php

/**
 * BOXLETCMS.ORG FOR v0.1.0 (PLAN.md D-274, the owner, 2026-10-15): AI translation on the home
 * page and the Languages page, with the demo as the example the owner gave (14 pages into
 * Croatian with Claude Opus 5.5, its menu, pictures and forms included, about 30 cents); copying
 * into a language and what comes with it; forms that keep messages only when told to (D-269),
 * where the Forms page said every message is kept; the changelog's v0.1.0; the Download page's
 * words for a release. Run once, through the site's own code, as content-1.php was:
 *
 *   php tools/site/content-2.php <site root> <site address> <folder of the new pictures> [--dry]
 *
 * With --dry every change is made in one transaction, said, and rolled back: no picture is
 * uploaded (an existing one stands in), nothing is kept, the sitemap and the kept pages are not
 * touched. The rehearsal on the live database itself, read and put back.
 *
 * A page with a draft is left and said. An edit finds its block by the words it replaces, and a
 * new section by its heading, so a second run changes nothing.
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
    fwrite(STDERR, "Usage: php tools/site/content-2.php <site root> <site address> <folder of the new pictures> [--dry]\n");
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
$newPictures = [
    'ai-translated.png' => 'Boxlet\'s translation screen: twelve of the demo\'s pages translated into Croatian, each a draft',
];
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

require __DIR__ . '/content-2-words.php';

if ($dry) {
    $db->pdo()->beginTransaction();
}

// ---- the home page: the Languages card, a section, a question ---------------------------------
$page('', static function (array $blocks, array $sections) use ($words, $has, $sectionOf, $insert, $reference): array {
    $said = [];
    foreach ($blocks as $i => $block) {
        if ($block['type'] === 'cards') {
            foreach ($block['content']['items'] as $n => $item) {
                if ($item['heading'] === 'Languages from day one') {
                    $blocks[$i]['content']['items'][$n]['heading'] = $words['home_card'][0];
                    $blocks[$i]['content']['items'][$n]['body'] = $words['home_card'][1];
                    $said[] = 'the Languages card';
                }
            }
        }
        if ($block['type'] === 'accordion' && !in_array($words['home_question'][0], array_column($block['content']['items'], 'question'), true)) {
            $items = $block['content']['items'];
            $after = array_search('How is it different from WordPress?', array_column($items, 'question'), true);
            $answer = (string) preg_replace_callback('~(?<=href=")site:([a-z0-9/-]*)(?=")~', static fn (array $m): string => $reference($m[1]), $words['home_question'][1]);
            array_splice($items, $after === false ? count($items) : $after + 1, 0, [['question' => $words['home_question'][0], 'answer' => $answer]]);
            $blocks[$i]['content']['items'] = $items;
            $said[] = 'a question about AI';
        }
    }
    if (!$has($blocks, $words['home_section'][1]['heading'])) {
        $at = $sectionOf($blocks, $sections, "An admin that's nice to be in");
        if ($at !== null) {
            [$blocks, $sections] = $insert($blocks, $sections, $at, [['blocks' => [$words['home_section']]]]);
            $said[] = 'the AI section';
        }
    }

    return [$blocks, $sections, $said];
});

// ---- Features: the Multilingual card ------------------------------------------------------------
$page('features', static function (array $blocks, array $sections) use ($words): array {
    $said = [];
    foreach ($blocks as $i => $block) {
        foreach ($block['type'] === 'cards' ? $block['content']['items'] : [] as $n => $item) {
            if ($item['heading'] === 'Multilingual from day one' && $item['body'] !== $words['features_card']) {
                $blocks[$i]['content']['items'][$n]['body'] = $words['features_card'];
                $said[] = 'the Multilingual card';
            }
        }
    }

    return [$blocks, $sections, $said];
});

// ---- Languages: AI translation after "Translate a page in one press"; what follows ------------------
$page('features/languages', static function (array $blocks, array $sections) use ($words, $has, $sectionOf, $insert): array {
    $said = [];
    foreach ($blocks as $i => $block) {
        if (($block['content']['heading'] ?? null) === 'What follows by itself' && $block['content']['body'] !== $words['follows']) {
            $blocks[$i]['content']['body'] = $words['follows'];
            $said[] = 'what follows by itself';
        }
    }
    if (!$has($blocks, $words['languages'][0]['blocks'][0][1]['heading'])) {
        $at = $sectionOf($blocks, $sections, 'Translate a page in one press');
        if ($at !== null) {
            [$blocks, $sections] = $insert($blocks, $sections, $at, $words['languages']);
            $said[] = count($words['languages']) . ' sections on AI translation';
        }
    }

    return [$blocks, $sections, $said];
});

// ---- Forms: what is kept is the owner's choice now (D-269) --------------------------------------
$page('features/forms', static function (array $blocks, array $sections) use ($words): array {
    $said = [];
    foreach ($blocks as $i => $block) {
        $heading = $block['content']['heading'] ?? null;
        if ($block['type'] === 'gallery' && $heading === 'Messages are kept in the admin') {
            $blocks[$i]['content']['heading'] = $words['forms_gallery'][0];
            $blocks[$i]['content']['items'][0]['caption'] = $words['forms_gallery'][1];
            $said[] = 'the messages gallery';
        } elseif ($heading === 'Every message is kept') {
            $blocks[$i]['content']['heading'] = $words['forms_kept'][0];
            $blocks[$i]['content']['body'] = $words['forms_kept'][1];
            $said[] = 'what is kept';
        } elseif ($heading === 'Making a form' && !str_contains((string) $block['content']['body'], 'copied into another language')) {
            $blocks[$i]['content']['body'] = $words['forms_making'];
            $said[] = 'making a form';
        }
    }

    return [$blocks, $sections, $said];
});

// ---- the changelog's v0.1.0, first ---------------------------------------------------------------
$page('changelog', static function (array $blocks, array $sections) use ($words, $has, $insert): array {
    if ($has($blocks, $words['changelog'][1]['heading'])) {
        return [$blocks, $sections, []];
    }
    [$blocks, $sections] = $insert($blocks, $sections, 0, [['blocks' => [$words['changelog']]]]);

    return [$blocks, $sections, ['v0.1.0 added']];
});

// ---- Download: a release, not a candidate -------------------------------------------------------
$page('get', static function (array $blocks, array $sections) use ($words): array {
    $said = [];
    foreach ($blocks as $i => $block) {
        $body = $block['content']['body'] ?? null;
        if (is_string($body) && str_contains($body, 'a release candidate for v0.1')) {
            $blocks[$i]['content']['body'] = str_replace('a release candidate for v0.1', $words['get'], $body);
            $said[] = 'the version line';
        }
    }

    return [$blocks, $sections, $said];
});

if ($dry) {
    $db->pdo()->rollBack();
    echo "Rehearsal: every change rolled back.\n";
    exit(0);
}
Sitemap::refresh($container);
PageCache::clear();
echo "Sitemap, robots.txt and llms.txt written; kept pages emptied.\n";
