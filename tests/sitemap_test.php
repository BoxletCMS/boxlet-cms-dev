<?php

use App\Modules\Pages\Sitemap;
use App\Modules\Pages\Translations;

// The sitemap (PLAN.md D-049). adminSite() and adminPost() come from pages_admin_test.php.
// Files are written into the test's own public directory (PUBLIC_PATH), never a real site's.

/** The test's public directory, made empty. */
function sitemapRoot(): string
{
    $root = (string) (TestSite::$env['PUBLIC_PATH'] ?? '');
    removeTree($root);
    mkdir($root, 0700, true);

    return $root;
}

testBothDrivers('the sitemap lists every published page, translations as alternates, drafts and switched-off languages left out', function (string $driver) {
    $db = adminSite($driver);
    $about = createPage($db, 'en', 'about', 'About');
    createPage($db, 'en', 'soon', 'Soon', false);
    $onama = (int) Translations::create($db, blockRegistry(), $about, 'hr');
    $db->query("UPDATE pages SET slug = 'o-nama', status = 'published' WHERE id = ?", [$onama]);

    $xml = dispatch('/sitemap')->body;
    $doc = simplexml_load_string($xml);
    assertTrue($doc !== false, 'not XML: ' . substr($xml, 0, 120));
    assertContains('<loc>http://example.test/about</loc>', $xml, 'a page');
    assertContains('<loc>http://example.test/hr/o-nama</loc>', $xml, 'its translation');
    assertContains('<xhtml:link rel="alternate" hreflang="hr" href="http://example.test/hr/o-nama"/>', $xml, 'the alternate');
    assertTrue(!str_contains($xml, '/soon'), 'a draft');

    $db->query('UPDATE locales SET enabled = 0 WHERE code = ?', ['hr']);
    assertTrue(!str_contains(dispatch('/sitemap')->body, 'o-nama'), 'a page in a language switched off');
});

testBothDrivers('publishing a page writes sitemap.xml and a robots.txt that points at it', function (string $driver) {
    $db = adminSite($driver);
    $root = sitemapRoot();
    $page = createPage($db, 'en', 'about', 'About', false);

    adminPost("/admin/pages/{$page}/status", ['status' => 'published']);
    assertContains('<loc>http://example.test/about</loc>', (string) @file_get_contents($root . '/sitemap.xml'), 'the file after publishing');
    $robots = (string) @file_get_contents($root . '/robots.txt');
    assertContains('Sitemap: http://example.test/sitemap.xml', $robots, 'robots.txt');
    assertContains('Disallow: /admin', $robots, 'the admin kept out');

    adminPost("/admin/pages/{$page}/status", ['status' => 'draft']);
    assertTrue(!str_contains((string) @file_get_contents($root . '/sitemap.xml'), '/about'), 'the file after unpublishing');
});

testBothDrivers('a robots.txt the owner wrote is never replaced', function (string $driver) {
    $db = adminSite($driver);
    $root = sitemapRoot();
    file_put_contents($root . '/robots.txt', "User-agent: *\nDisallow: /private\n");

    assertTrue(Sitemap::publish($db, $root), 'the sitemap was not written');
    assertEquals("User-agent: *\nDisallow: /private\n", (string) file_get_contents($root . '/robots.txt'), 'the owner\'s robots.txt');
});

test('where public/ cannot be written, nothing fails and the sitemap is still at /sitemap', function () {
    $db = installedSite(['en' => 'English']);
    assertEquals(false, Sitemap::publish($db, tmpPath('no-such-directory')), 'reported as written');
});

testBothDrivers('a site with no sitemap file gets one when the owner opens the dashboard', function (string $driver) {
    adminSite($driver);
    $root = sitemapRoot();

    dispatch('/admin');
    assertTrue(is_file($root . '/sitemap.xml'), 'no sitemap after opening the dashboard');
});
