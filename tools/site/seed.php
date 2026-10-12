<?php

/**
 * BOXLETCMS.ORG, BUILT IN BOXLET (PLAN.md D-228): a one-off seed, run once against that site's
 * own install, through that install's own code. Not in the release ZIP (tools/ never is).
 *
 *   php tools/site/seed.php <site root> <screenshots folder> <site address>
 *
 * The address (https://boxletcms.org) is the one the site answers on: robots.txt names its
 * sitemap by it, and a command line has no request to take it from.
 *
 * In order: the design set in this folder added as a character and chosen; the screenshots
 * uploaded once, each with its alt text; the snippets `version` and `requirements`; the
 * logo; seventeen pages, published; the header's menu and button; the footer's three columns
 * and small print; sitemap.xml, robots.txt and llms.txt, as publishing in the admin writes
 * them. Refuses a site that already has pages, as the demo's seed does.
 *
 * The pages are pages.php; their markers are resolved here: `pic:{file}` by the picture's
 * id, `site:{path}` (in a link or an href) by a reference to that page, `site:` the home.
 */

use App\Core\BlockOptions;
use App\Core\Settings;
use App\Modules\Demo\DemoPictures;
use App\Modules\Design\Characters;
use App\Modules\Design\Composition;
use App\Modules\Design\Design;
use App\Modules\Design\DesignSet;
use App\Modules\Design\SectionStyle;
use App\Modules\Media\MediaMeta;
use App\Modules\Menus\Menu;
use App\Modules\Pages\BlockForm;
use App\Modules\Pages\Page;
use App\Modules\Pages\PageLinks;
use App\Modules\Pages\PageSeo;
use App\Modules\Pages\SectionForm;
use App\Modules\Settings\LogoSvg;
use App\Modules\Settings\SiteChrome;
use App\Modules\Snippets\Snippets;
use App\Support\RichText;
use App\Support\SvgSanitizer;

if (PHP_SAPI !== 'cli' || $argc !== 4) {
    fwrite(STDERR, "Usage: php tools/site/seed.php <site root> <screenshots folder> <site address>\n");
    exit(1);
}
[, $site, $shots, $address] = $argv;
$origin = parse_url($address);
if (!is_array($origin) || !in_array($origin['scheme'] ?? '', ['http', 'https'], true) || !isset($origin['host'])) {
    fwrite(STDERR, "The site address is a full one, such as https://boxletcms.org\n");
    exit(1);
}
$https = $origin['scheme'] === 'https';
$site = rtrim((string) realpath($site), '/');
$shots = rtrim((string) realpath($shots), '/');
if (!is_file($site . '/app/bootstrap.php') || !is_dir($shots)) {
    fwrite(STDERR, "Not a Boxlet install, or no screenshots folder.\n");
    exit(1);
}

// As a request would find it: the install's own folder, its .env, a server to answer for.
chdir($site);
$_SERVER += ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php', 'SERVER_NAME' => $origin['host'], 'SERVER_PORT' => (string) ($origin['port'] ?? ($https ? 443 : 80)), 'HTTPS' => $https ? 'on' : 'off', 'REMOTE_ADDR' => '127.0.0.1'];
require $site . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($site)->safeLoad();
/** @var App\Core\Container $container */
$container = require $site . '/app/bootstrap.php';
// Addresses are configured where a request builds its router: the primary language, the
// origin and the nested paths. Without it the sitemap listed /en/privacy, no origin, for
// https://boxletcms.org/features/privacy (D-228).
$container->get('router');
$db = $container->get('db');
$registry = $container->get('blocks');
$config = $container->get('config');
$locale = (string) ($db->one('SELECT code FROM locales WHERE is_primary = 1')['code'] ?? '');
if ($locale === '') {
    fwrite(STDERR, "Boxlet is not installed yet.\n");
    exit(1);
}
if ((int) ($db->one('SELECT COUNT(*) AS n FROM pages')['n'] ?? 0) > 0) {
    fwrite(STDERR, "The site already has pages. This seed is only for an empty site.\n");
    exit(1);
}
$say = static function (string $line): void {
    echo $line, "\n";
};

// ---- the design -------------------------------------------------------------------------
$read = DesignSet::parse((string) file_get_contents(__DIR__ . '/boxlet-site.json'), $registry);
if ($read['set'] === null) {
    fwrite(STDERR, "The design set is refused:\n  " . implode("\n  ", $read['errors']) . "\n");
    exit(1);
}
$character = Characters::addImported($db, $read['set']);
Composition::remember($db, $character);
$cache = (string) $config->get('app.cache_path');
Design::save($db, Characters::decisions($character), $cache, []);
$say("Design: {$character}, added as a character and chosen.");

// ---- the pictures -----------------------------------------------------------------------
$app = (array) $config->get('app', []);
$store = DemoPictures::importer($db, (string) ($app['storage_path'] ?? $site . '/storage'), (string) ($app['public_path'] ?? $site . '/public'));
$pictures = [];
foreach (require __DIR__ . '/pictures.php' as $file => $alt) {
    $path = $shots . '/' . $file;
    if (!is_file($path)) {
        fwrite(STDERR, "Missing picture: {$file}\n");
        exit(1);
    }
    $pictures[$file] = $store($path, $file);
    MediaMeta::save($db, $pictures[$file], $locale, $alt, '');
}
$say('Pictures: ' . count($pictures) . ' uploaded, each with its alt text.');

// ---- snippets and logo ------------------------------------------------------------------
Snippets::save($db, 'version', [$locale => 'v0.1.0-rc.4']);
Snippets::save($db, 'requirements', [$locale => 'PHP 8.1 or newer, MySQL, MariaDB or SQLite, and Apache or nginx']);
$public = (string) ($app['public_path'] ?? $site . '/public');
foreach (['logo' => ['#c13c11', '#fbf7f1'], 'logo_dark' => ['#ff7a45', '#1c1a17']] as $slot => [$square, $inner]) {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">'
        . '<rect width="100" height="100" rx="25" fill="' . $square . '"/>'
        . '<rect x="30" y="30" width="40" height="40" rx="5" fill="none" stroke="' . $inner . '" stroke-width="10"/></svg>';
    $clean = SvgSanitizer::clean($svg);
    LogoSvg::store($db, $public, $slot, $clean['svg'], $clean['width'], $clean['height']);
}
Settings::set($db, 'site_name', 'Boxlet');
$say('Snippets version and requirements, the logo for light and dark, the name Boxlet.');

// ---- the pages --------------------------------------------------------------------------
$pages = require __DIR__ . '/pages.php';
// Each address as the admin would take it: a reserved one ("download", "install", "admin")
// is refused there and was written here without a word (D-228).
foreach ($pages as $page) {
    $problem = App\Modules\Pages\Slug::problem($db, $locale, basename('/' . $page['path']), null);
    if ($problem !== null) {
        fwrite(STDERR, "/{$page['path']}: {$problem}\n");
        exit(1);
    }
}
$ids = [];
foreach ($pages as $page) {
    $ids[$page['path']] = Page::create($db, $registry, $locale, $page['title'], basename('/' . $page['path']), null, []);
}
$reference = static function (string $path) use ($ids): string {
    if (!isset($ids[$path])) {
        throw new RuntimeException("No page {$path}");
    }

    return PageLinks::to($ids[$path]);
};
// Markers to ids and references, through the content's own fields, repeaters included.
$resolve = static function (array $fields, array $content) use (&$resolve, $reference, $pictures): array {
    foreach ($fields as $name => $field) {
        $value = $content[$name] ?? null;
        if ($field['type'] === 'media' && is_string($value) && str_starts_with($value, 'pic:')) {
            $content[$name] = $pictures[substr($value, 4)] ?? throw new RuntimeException("No picture {$value}");
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
foreach ($pages as $page) {
    $sections = [];
    $blocks = [];
    foreach ($page['sections'] as $at => $section) {
        $key = SectionForm::key(null, $at);
        $sections[] = ['key' => $key, 'id' => null, 'layout' => 'one', 'stack' => null, 'style' => SectionStyle::normalize($section['style'])];
        foreach ($section['blocks'] as [$type, $content, $layout, $options]) {
            $blocks[] = [
                'key' => BlockForm::key(null, count($blocks)),
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
    $parent = dirname('/' . $page['path']);
    Page::update($db, $registry, $ids[$page['path']], [
        'title' => $page['title'],
        'slug' => basename('/' . $page['path']),
        'parent_id' => $parent === '/' ? null : $ids[ltrim($parent, '/')],
        'status' => 'draft',
        'seo_json' => PageSeo::json(['title' => $page['seo_title'] ?? '', 'description' => $page['description'], 'noindex' => false]),
    ], $blocks, $sections);
    Page::setStatus($db, $ids[$page['path']], true);
}
$say('Pages: ' . count($ids) . ', published.');

// ---- header and footer ------------------------------------------------------------------
$menu = static function (string $name, array $items) use ($db, $locale, $ids): void {
    $id = Menu::create($db, $locale, $name);
    foreach ($items as [$label, $to]) {
        $page = str_starts_with($to, 'site:') ? $ids[substr($to, 5)] : null;
        Menu::addItem($db, $id, null, $page, $page === null ? $to : null, $label);
    }
};
$menu('Main', [['Why Boxlet', 'site:why'], ['Features', 'site:features'], ['Docs', 'site:docs'], ['Demo ↗', 'https://demo.boxletcms.org']]);
$menu('Product', [['Why Boxlet', 'site:why'], ['Features', 'site:features'], ['Demo', 'https://demo.boxletcms.org'], ['Download', 'site:get']]);
$menu('Help', [['Installing', 'site:docs/installing'], ['Updating', 'site:docs/updating'], ['Backups', 'site:docs/backups'], ['Locked out?', 'site:docs/locked-out'], ['GitHub ↗', 'https://github.com/BoxletCMS/boxlet-cms-dev']]);
SiteChrome::saveShared($db, 'Main', [1 => SiteChrome::FOOTER_MENU_NONE, 2 => 'Product', 3 => 'Help']);
SiteChrome::saveForLocale($db, $locale, [
    'button_label' => 'Download',
    'button_url' => $reference('get'),
    'columns' => [
        ['title' => 'Boxlet', 'text' => '<p>A small, self-hosted CMS for people who build lots of small websites. MIT licence.</p>'],
        ['title' => 'Product', 'text' => ''],
        ['title' => 'Help', 'text' => ''],
    ],
    'small_print' => '© {{year}} Boxlet · No cookies on this site, not even one.',
]);
$say('Menus Main, Product and Help; the header\'s Download button; the footer.');

Design::publish($db, $cache);
// What the admin's Publish writes after a page changes (PagePublish): the first run left the
// empty sitemap and the install's llms.txt behind, under the site's old name.
if (!App\Modules\Pages\Sitemap::refresh($container)) {
    $say('sitemap.xml, robots.txt and llms.txt could not be written: public/ is not writable.');
}
$say('Done. The stylesheet is compiled; sitemap.xml, robots.txt and llms.txt are written.');
