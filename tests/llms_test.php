<?php

use App\Core\Settings;
use App\Modules\Pages\LlmsTxt;
use App\Modules\Pages\Sitemap;
use App\Modules\Pages\Translations;

// llms.txt (PLAN.md D-151). adminSite() and adminPost() come from pages_admin_test.php,
// sitemapRoot() from sitemap_test.php: the file is written into the test's own public
// directory, never a real site's.

/** A page's stored description, as the editor's SEO fields would save it. */
function describePage(App\Core\Db $db, int $page, string $description): void
{
    $db->query('UPDATE pages SET seo_json = ? WHERE id = ?', [json_encode(['description' => $description]), $page]);
}

testBothDrivers('llms.txt names the site, sums it up, and lists every published page in every language', function (string $driver) {
    $db = adminSite($driver);
    Settings::set($db, 'site_name', 'Northwind Studio');
    $home = (int) ($db->one("SELECT id FROM pages WHERE locale = 'en' AND slug = ''")['id'] ?? createPage($db, 'en', '', 'Home'));
    $db->query("UPDATE pages SET status = 'published' WHERE id = ?", [$home]);
    describePage($db, $home, "A design studio\nin Zagreb.");
    $about = createPage($db, 'en', 'about', 'About [us]');
    describePage($db, $about, 'Who we are.');
    createPage($db, 'en', 'soon', 'Soon', false);
    $onama = (int) Translations::create($db, blockRegistry(), $about, 'hr');
    $db->query("UPDATE pages SET slug = 'o-nama', title = 'O nama', status = 'published' WHERE id = ?", [$onama]);

    // The file as an admin action writes it: the addresses are absolute only inside a
    // request, which is where it is always written from.
    $root = sitemapRoot();
    $written = static function () use ($root): string {
        adminPost('/admin/settings/llms', ['llms_txt' => '1']);

        return (string) @file_get_contents($root . '/llms.txt');
    };
    $text = $written();
    assertTrue(str_starts_with($text, "# Northwind Studio\n"), 'an H1 with the site\'s name first: ' . substr($text, 0, 60));
    // The home page's description, on one line: a line break would end the quote.
    assertContains("\n> A design studio in Zagreb.\n", $text, 'the summary');
    assertContains("## Pages\n", $text, 'the main language\'s section');
    assertContains('- [About \\[us\\]](http://example.test/about): Who we are.', $text, 'a page, its brackets escaped');
    assertContains("## Hrvatski\n", $text, 'another language, named in it');
    assertContains('- [O nama](http://example.test/hr/o-nama)', $text, 'its page');
    assertTrue(!str_contains($text, '/soon'), 'a draft');
    // The home page leads its section.
    assertTrue(strpos($text, '(http://example.test/)') < strpos($text, '(http://example.test/about)'), 'the home page is not first');
    // A page under another is listed under it, one step in, whatever the sort says.
    $team = createPage($db, 'en', 'team', 'Team');
    $db->query('UPDATE pages SET parent_id = ?, sort = -5 WHERE id = ?', [$about, $team]);
    $db->query("UPDATE pages SET slug = 'about/team' WHERE id = ?", [$team]);
    $tree = $written();
    assertContains("Who we are.\n  - [Team](http://example.test/about/team)", $tree, 'a child under its parent');
    assertTrue(str_ends_with(trim($text), LlmsTxt::MARK), 'Boxlet\'s mark on the last line');

    $db->query('UPDATE locales SET enabled = 0 WHERE code = ?', ['hr']);
    assertTrue(!str_contains($written(), 'o-nama'), 'a page in a language switched off');
});

testBothDrivers('publishing a page writes llms.txt beside the sitemap, and the switch removes it', function (string $driver) {
    $db = adminSite($driver);
    $root = sitemapRoot();
    $page = createPage($db, 'en', 'about', 'About', false);

    adminPost("/admin/pages/{$page}/status", ['status' => 'published']);
    assertContains('(http://example.test/about)', (string) @file_get_contents($root . '/llms.txt'), 'the file after publishing');

    adminPost('/admin/settings/llms', []);
    assertTrue(!is_file($root . '/llms.txt'), 'the file after switching it off');
    adminPost("/admin/pages/{$page}/status", ['status' => 'draft']);
    assertTrue(!is_file($root . '/llms.txt'), 'a later publish wrote it back while switched off');

    adminPost('/admin/settings/llms', ['llms_txt' => '1']);
    assertTrue(is_file($root . '/llms.txt'), 'the file after switching it on');
});

// No route: managed nginx answers an address ending in .txt from disk and never asks PHP
// (routing_test.php), so where the file cannot be written the panel says so instead.
test('where public/ cannot be written, the Settings panel says llms.txt is missing', function () {
    adminSite('sqlite');
    $root = sitemapRoot();
    chmod($root, 0500);
    try {
        adminPost('/admin/settings/llms', ['llms_txt' => '1']);
        assertTrue(!is_file($root . '/llms.txt'), 'a file appeared in a folder that cannot be written');
        assertContains('could not be written', dispatch('/admin/settings')->body, 'the panel');
    } finally {
        chmod($root, 0700);
    }
});

testBothDrivers('renaming the site renames llms.txt', function (string $driver) {
    $db = adminSite($driver);
    $root = sitemapRoot();
    adminPost('/admin/settings', ['site_name' => 'Renamed Studio', 'timezone' => 'Europe/Zagreb']);
    assertTrue(str_starts_with((string) @file_get_contents($root . '/llms.txt'), "# Renamed Studio\n"), 'the file after renaming');
});

testBothDrivers('an llms.txt the owner wrote is never replaced or removed', function (string $driver) {
    $db = adminSite($driver);
    $root = sitemapRoot();
    file_put_contents($root . '/llms.txt', "# My own\n");

    assertTrue(Sitemap::publish($db, $root), 'the sitemap was not written');
    assertEquals("# My own\n", (string) file_get_contents($root . '/llms.txt'), 'the owner\'s file after a publish');
    Settings::set($db, 'llms_txt', '0');
    LlmsTxt::publish($db, $root);
    assertEquals("# My own\n", (string) file_get_contents($root . '/llms.txt'), 'the owner\'s file after switching off');
    assertTrue(LlmsTxt::ownersOwn($root), 'the Settings panel is not told it is the owner\'s');
});
