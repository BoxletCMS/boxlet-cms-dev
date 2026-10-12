<?php

/**
 * BOXLETCMS.ORG, A CONTACT PAGE AND GITHUB'S ADDRESS (PLAN.md D-229, the owner): run once
 * against the live site, through its own code, as seed.php was.
 *
 *   php tools/site/contact.php <site root> <site address>
 *
 *   - GitHub, in the footer's Help and in Docs' "Ask on GitHub", leads to BoxletCMS/Boxlet-CMS,
 *     the repository of the install code (its issues are open); CREDITS.md stays where it is,
 *     in boxlet-cms-dev, the only repository that has it;
 *   - Contact, at /contact: a hero, then words on the left and an empty column on the right,
 *     where the owner puts the form; in the footer's Help, before GitHub.
 *
 * Refuses a site that has a Contact page already, and leaves Docs alone while it has a draft:
 * the owner's unsaved work is not written over.
 */

use App\Modules\Admin\Activity;
use App\Modules\Menus\Menu;
use App\Modules\Menus\MenuTree;
use App\Modules\Pages\BlockForm;
use App\Modules\Pages\Page;
use App\Modules\Pages\PageBlocks;
use App\Modules\Pages\PageDraft;
use App\Modules\Pages\PageSeo;
use App\Modules\Pages\SectionForm;
use App\Modules\Pages\Sitemap;
use App\Modules\Pages\Slug;
use App\Modules\Design\SectionStyle;
use App\Support\PageCache;

const DEV_REPO = 'https://github.com/BoxletCMS/boxlet-cms-dev';
const INSTALL_REPO = 'https://github.com/BoxletCMS/Boxlet-CMS';

if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR, "Usage: php tools/site/contact.php <site root> <site address>\n");
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
$problem = Slug::problem($db, $locale, 'contact', null);
if ($problem !== null) {
    fwrite(STDERR, "/contact: {$problem}\n");
    exit(1);
}
$help = $db->one('SELECT id, name FROM menus WHERE locale = ? AND name = ?', [$locale, 'Help']);
if ($help === null) {
    fwrite(STDERR, "No menu named Help.\n");
    exit(1);
}

// ---- GitHub: the footer's item, and Docs' line -------------------------------------------
foreach ($db->all('SELECT id, page_id, label FROM menu_items WHERE menu_id = ? AND url = ?', [(int) $help['id'], DEV_REPO]) as $item) {
    Menu::updateItem($db, (int) $item['id'], null, INSTALL_REPO, (string) $item['label']);
    echo "Help: {$item['label']} leads to " . INSTALL_REPO . "\n";
}
$docs = $db->one("SELECT id, title, slug, parent_id, status, seo_json FROM pages WHERE locale = ? AND slug = 'docs' AND parent_id IS NULL", [$locale]);
if ($docs !== null && PageDraft::exists($db, (int) $docs['id'])) {
    echo "Docs has a draft: left alone. Its \"Ask on GitHub\" still leads to " . DEV_REPO . "/issues.\n";
} elseif ($docs !== null) {
    $blocks = PageBlocks::editable($db, $registry, (int) $docs['id']);
    $changed = false;
    foreach ($blocks as $at => $block) {
        $body = $block['content']['body'] ?? null;
        if ($block['type'] === 'text' && is_string($body) && str_contains($body, DEV_REPO . '/issues')) {
            $blocks[$at]['content']['body'] = str_replace(DEV_REPO . '/issues', INSTALL_REPO . '/issues', $body);
            $changed = true;
        }
    }
    if ($changed) {
        Page::update($db, $registry, (int) $docs['id'], [
            'title' => (string) $docs['title'],
            'slug' => (string) $docs['slug'],
            'parent_id' => null,
            'status' => (string) $docs['status'],
            'seo_json' => (string) $docs['seo_json'],
        ], $blocks, PageBlocks::editableSections($db, (int) $docs['id']));
        Activity::record($db, 'page', 'saved', (int) $docs['id'], (string) $docs['title']);
        echo "Docs: \"Ask on GitHub\" leads to " . INSTALL_REPO . "/issues\n";
    }
}

// ---- Contact -------------------------------------------------------------------------------
$p = static fn (string ...$paragraphs): string => implode('', array_map(static fn (string $s): string => '<p>' . $s . '</p>', $paragraphs));
$id = Page::create($db, $registry, $locale, 'Contact', 'contact', null, []);
$hero = SectionForm::key(null, 0);
$words = SectionForm::key(null, 1);
Page::update($db, $registry, $id, [
    'title' => 'Contact',
    'slug' => 'contact',
    'parent_id' => null,
    'status' => 'draft',
    'seo_json' => PageSeo::json(['title' => '', 'description' => 'An idea for Boxlet, or a project that needs something it doesn\'t do yet? Tell us.', 'noindex' => false]),
], [
    [
        'key' => BlockForm::key(null, 0), 'id' => null, 'type' => 'hero',
        'content' => $registry->normalize('hero', ['heading' => 'Get in touch', 'subheading' => 'An idea, a question, or something Boxlet doesn\'t do yet.']),
        'style' => SectionStyle::normalize([]), 'options' => ['height' => 'auto'], 'layout' => '', 'section' => $hero, 'column' => 0,
    ],
    [
        'key' => BlockForm::key(null, 1), 'id' => null, 'type' => 'text',
        'content' => $registry->normalize('text', ['heading' => 'Tell us what you have in mind', 'body' => $p(
            'Boxlet does a short list of things on purpose, and it will stay small. That doesn\'t mean it\'s finished. If you\'ve built a few sites with it and keep wishing it did one thing differently, or you\'ve noticed something it should simply do, we\'d like to hear about it.',
            'If a project needs something Boxlet doesn\'t do, write to us too: a block for one particular kind of content, a link to a service your client already uses, or a feature that only makes sense for one site. Tell us what you need and roughly by when, and we\'ll tell you plainly whether it can be done and what it would take.',
            'Found a bug? <a href="' . INSTALL_REPO . '/issues">GitHub</a> is the quickest place for those.',
        )]),
        'style' => SectionStyle::normalize([]), 'options' => [], 'layout' => '', 'section' => $words, 'column' => 0,
    ],
], [
    ['key' => $hero, 'id' => null, 'layout' => 'one', 'stack' => null, 'style' => SectionStyle::normalize([])],
    // The second column is the form's: empty until the owner puts it there.
    ['key' => $words, 'id' => null, 'layout' => 'halves', 'stack' => null, 'style' => SectionStyle::normalize([])],
]);
Page::setStatus($db, $id, true);
Activity::record($db, 'page', 'created', $id, 'Contact');
Activity::record($db, 'page', 'published', $id, 'Contact');
echo "Contact: /contact, published, its form's column empty\n";

// In Help, before GitHub.
$item = Menu::addItem($db, (int) $help['id'], null, $id, null, null);
$last = $db->one('SELECT url FROM menu_items WHERE menu_id = ? AND parent_id IS NULL ORDER BY sort DESC LIMIT 1 OFFSET 1', [(int) $help['id']]);
if ($item !== null && ($last['url'] ?? null) === INSTALL_REPO) {
    MenuTree::move($db, $item, 'up');
}
Activity::record($db, 'menu', 'edited', (int) $help['id'], (string) $help['name']);
echo "Help: Contact added before GitHub\n";

// What the admin's Publish and any admin change do: the sitemap and llms.txt, and the kept
// pages emptied (every page's footer changed).
Sitemap::refresh($container);
PageCache::clear();
echo "Sitemap, robots.txt and llms.txt written; kept pages emptied.\n";
