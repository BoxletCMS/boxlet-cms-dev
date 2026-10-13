<?php

/**
 * BOXLETCMS.ORG'S TITLES AND DESCRIPTIONS FOR SEARCH (PLAN.md D-244, the owner): the 18 pages'
 * search titles and descriptions from the SEO analysis of 2026-10-13, each with the words people
 * search for and the name Boxlet CMS. Run once against the live site, through its own code, as
 * four-scripts.php was.
 *
 *   php tools/site/seo-titles.php <site root> <site address>
 *
 * Each page is changed only while it stands as the seed wrote it: no search title of its own and
 * the seed's description. A page with a draft, or one the owner has given words of their own, is
 * left and said. The page's other SEO (kept out of search engines, its sharing picture) is kept.
 */

use App\Modules\Admin\Activity;
use App\Modules\Pages\Page;
use App\Modules\Pages\PageBlocks;
use App\Modules\Pages\PageDraft;
use App\Modules\Pages\PageSeo;
use App\Modules\Pages\Sitemap;
use App\Support\PageCache;

if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR, "Usage: php tools/site/seo-titles.php <site root> <site address>\n");
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

/** address => [the search title, the description, the description the seed wrote, and the search title it wrote] */
$words = [
    '/' => ['Boxlet CMS: a lightweight PHP CMS for small websites', 'Self-hosted and open source. Runs on ordinary shared hosting with MySQL or SQLite: no shell, no Composer, no build step. Multilingual and cookie-free.', 'Self-hosted, no cookies, multilingual. Upload a ZIP, answer four questions, and you have a site that looks designed.', 'Boxlet CMS, for people who build lots of small websites'],
    '/why' => ['Why Boxlet: a simpler alternative to WordPress', 'A five-page site shouldn\'t need plugins, a page builder and a cookie banner. What Boxlet CMS leaves out, what it does instead, and fair questions.', 'Because a five-page site shouldn\'t take an afternoon of removing things.'],
    '/features' => ['Boxlet CMS features: design, pages, languages, forms', 'A visual page editor, 21 design sets, AVIF and WebP pictures, multilingual pages, forms and cookie-free statistics, in one small self-hosted CMS.', 'Boxlet CMS does a short list of things and tries to do each one properly.'],
    '/features/privacy' => ['A cookie-free CMS with its own statistics · Boxlet CMS', 'Boxlet sets no cookies for visitors, so a site needs no cookie banner. Visits are counted on your own server, without a tracking script.', 'Boxlet CMS doesn\'t set a single cookie for your visitors. Not on pages, not on the 404, not when someone sends a form.'],
    '/features/design' => ['Website design without CSS: 21 design sets · Boxlet CMS', 'Pick a design set, then change colours, typefaces and spacing. 27 typefaces served from your own server, and text that is too hard to read is refused.', 'This is the part Boxlet CMS is really about.'],
    '/features/writing' => ['A visual page editor: write on the page · Boxlet CMS', 'Click a heading and type. Sections, columns, fifteen blocks, drafts that save themselves and a phone preview, in a PHP CMS for small sites.', 'Click a heading and type. Select words for bold, a link or a list.'],
    '/features/pictures' => ['Responsive images in AVIF and WebP · Boxlet CMS', 'Upload a picture once. Boxlet makes every size a page needs, in AVIF and WebP when your server can, with a focal point and crop.', 'Boxlet CMS makes every size a page needs, in AVIF and WebP when your server can.'],
    '/features/speed-seo' => ['Fast pages with SEO built in · Boxlet CMS', 'Next to no JavaScript and a page cache. Titles, descriptions, canonical links, hreflang, sitemap.xml, 301 redirects and llms.txt are built in.', 'It gets there by leaving things out, and by keeping what it can.'],
    '/features/languages' => ['A multilingual CMS with hreflang · Boxlet CMS', 'Add a language in one screen. Each language gets its own addresses, translations know each other, and hreflang and the language switcher come free.', 'Adding a language takes one screen.'],
    '/features/forms' => ['Contact forms without a plugin · Boxlet CMS', 'Build contact forms in the admin. Messages are kept in the admin and emailed through SMTP or Resend, and spam is stopped without a CAPTCHA.', 'Text, email, phone, long text, choice and checkbox fields.'],
    '/features/the-admin' => ['A clean CMS admin, light or dark · Boxlet CMS', 'An admin that\'s nice to be in: an overview of what needs attention, ⌘K to find anything, optional two-step login and maintenance mode.', 'Its own design, light or dark.'],
    '/docs' => ['Boxlet CMS documentation', 'How to install, update, back up and get back into a Boxlet CMS site. Short guides, because there isn\'t much to it.', 'Short, because there isn\'t much to it.'],
    '/docs/installing' => ['Install Boxlet CMS on shared hosting or cPanel', 'Upload one ZIP, open install.php and answer four questions. Needs PHP 8.1 and MySQL, MariaDB or SQLite. Works on cPanel without SSH or Composer.', 'Upload a ZIP, open a page, answer four questions.'],
    '/docs/updating' => ['Updating Boxlet CMS from the admin', 'Look for a newer version in the admin, or upload the ZIP yourself. A backup is made before anything changes, and you can roll back.', 'From the admin, with a backup first and a way back.'],
    '/docs/backups' => ['Backups and restoring · Boxlet CMS docs', 'Pages, settings, messages and every uploaded file in one ZIP. Download it, keep it elsewhere, and restore it with one button, even on a fresh install.', 'The whole site in one ZIP.'],
    '/docs/locked-out' => ['Locked out of Boxlet CMS? Reset password or 2FA', 'Forgot your password, or lost the phone with your two-step login? Both ways back in need only FTP or your host\'s file manager.', 'Both ways back in need only FTP or your host\'s file manager.'],
    '/get' => ['Download Boxlet CMS: free and open source (MIT)', 'Download Boxlet CMS from GitHub: one ZIP, MIT licensed. Upload it to any host with PHP 8.1 and install it from your browser in minutes.', 'Boxlet is MIT licensed. One ZIP, from GitHub.'],
    '/contact' => ['Contact · Boxlet CMS', 'An idea for Boxlet CMS, a bug, or a project that needs something it doesn\'t do yet? Write to us.', 'An idea for Boxlet, or a project that needs something it doesn\'t do yet? Tell us.'],
];

foreach ($words as $path => $said) {
    [$title, $description, $seeded] = $said;
    $seededTitle = $said[3] ?? '';
    // The page at this address: each segment a slug under the one before.
    $parent = null;
    $page = null;
    foreach ($path === '/' ? [''] : explode('/', trim($path, '/')) as $slug) {
        $page = $db->one(
            'SELECT id, title, slug, parent_id, status, seo_json FROM pages WHERE locale = ? AND slug = ? AND ' . ($parent === null ? 'parent_id IS NULL' : 'parent_id = ?'),
            $parent === null ? [$locale, $slug] : [$locale, $slug, $parent],
        );
        if ($page === null) {
            break;
        }
        $parent = (int) $page['id'];
    }
    if ($page === null) {
        echo "{$path}: no such page, left\n";
        continue;
    }
    if (PageDraft::exists($db, (int) $page['id'])) {
        echo "{$path}: has a draft, left alone\n";
        continue;
    }
    $seo = PageSeo::of($page);
    if ($seo['title'] === $title && $seo['description'] === $description) {
        echo "{$path}: already so\n";
        continue;
    }
    if ($seo['title'] !== $seededTitle || $seo['description'] !== $seeded) {
        echo "{$path}: not as the seed wrote it, left\n";
        continue;
    }
    Page::update($db, $registry, (int) $page['id'], [
        'title' => (string) $page['title'],
        'slug' => (string) $page['slug'],
        'parent_id' => $page['parent_id'] === null ? null : (int) $page['parent_id'],
        'status' => (string) $page['status'],
        'seo_json' => PageSeo::json(['title' => $title, 'description' => $description] + $seo),
    ], PageBlocks::editable($db, $registry, (int) $page['id']), PageBlocks::editableSections($db, (int) $page['id']));
    Activity::record($db, 'page', 'saved', (int) $page['id'], (string) $page['title']);
    echo "{$path}: written\n";
}

// What any admin change does: the sitemap and llms.txt, the kept pages.
Sitemap::refresh($container);
PageCache::clear();
echo "Sitemap, robots.txt and llms.txt written; kept pages emptied.\n";
