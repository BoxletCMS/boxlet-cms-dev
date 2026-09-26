<?php

use App\Modules\Pages\PagePaths;
use App\Modules\Pages\Sitemap;
use App\Support\Url;

/*
 * NESTED ADDRESSES (PLAN.md D-129, step 3): a page under a parent is /usluge/web-dizajn, the
 * last segment finds it, a wrong path in front of it is sent on, and search engines are
 * told the trail as BreadcrumbList JSON-LD. assertMovedTo() and renamePage() come from
 * redirects_test.php.
 */

/** The editor's save, placing the page under $parent (null: the top level). */
function placePage(int $id, string $title, string $slug, ?int $parent, string $status = 'published'): void
{
    assertRedirectedTo('/admin/pages/' . $id, adminPost("/admin/pages/{$id}", [
        'title' => $title,
        'slug' => $slug,
        'status' => $status,
        'parent_id' => $parent === null ? '' : (string) $parent,
        '_end' => '1',
    ]));
}

/**
 * The BreadcrumbList a page's head carries, decoded, or null when it has none.
 *
 * @return array<string, mixed>|null
 */
function breadcrumbsOf(string $html): ?array
{
    if (preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $match) !== 1) {
        return null;
    }
    $data = json_decode($match[1], true);

    return is_array($data) ? $data : null;
}

testBothDrivers('a page under a parent is addressed under it, and found by its last segment', function (string $driver) {
    $db = adminSite($driver);
    $services = createPage($db, 'en', 'services', 'Services');
    $web = createPage($db, 'en', 'web-design', 'Web design');
    placePage($web, 'Web design', 'web-design', $services);
    $seo = createPage($db, 'en', 'seo', 'SEO');
    placePage($seo, 'SEO', 'seo', $web);

    $page = dispatch('/services/web-design');
    assertEquals(200, $page->status, 'the nested address');
    assertContains('<link rel="canonical" href="http://example.test/services/web-design">', $page->body, 'and it is the canonical one');
    assertEquals(200, dispatch('/services/web-design/seo')->status, 'two levels down');

    // The address alone, or a path of real pages in front of it that is not its own, is
    // sent on, with its query. A prefix no page ever had is an address nobody made: 404.
    assertMovedTo('/services/web-design', dispatch('/web-design'), 'the slug alone');
    assertMovedTo('/services/web-design/seo?utm_source=news', dispatch('/services/seo?utm_source=news'), 'a path of real pages, not its own');
    assertEquals(404, dispatch('/elsewhere/seo')->status, 'a prefix no page ever had');
    assertEquals(404, dispatch('/services/nothing-here')->status, 'a slug nobody has, under a real parent');

    // Every address the site makes is the nested one: the page list, the sitemap.
    assertContains('/services/web-design/seo', dispatch('/admin/pages')->body, 'the page list');
    assertContains('<loc>http://example.test/services/web-design/seo</loc>', Sitemap::xml($db), 'the sitemap');
});

testBothDrivers('a renamed parent or a moved page sends its old addresses on', function (string $driver) {
    $db = adminSite($driver);
    $services = createPage($db, 'en', 'services', 'Services');
    $web = createPage($db, 'en', 'web-design', 'Web design');
    placePage($web, 'Web design', 'web-design', $services);

    renamePage($services, 'Services', 'offer');
    assertEquals(200, dispatch('/offer/web-design')->status, 'the child under the new name');
    assertMovedTo('/offer/web-design', dispatch('/services/web-design'), 'the child\'s old address');
    assertMovedTo('/offer', dispatch('/services'), 'the parent\'s old address');

    // Moved to the top level: its nested address leads to where it is now.
    placePage($web, 'Web design', 'web-design', null);
    assertMovedTo('/web-design', dispatch('/offer/web-design'), 'after a move');

    // And the child's own rename, while nested: the last segment is the old slug.
    placePage($web, 'Web design', 'web-design', $services);
    renamePage($web, 'Websites', 'websites');
    placePage($web, 'Websites', 'websites', $services);
    assertMovedTo('/offer/websites', dispatch('/offer/web-design'), 'a nested page\'s old slug');
});

testBothDrivers('the home page adds no segment, and a language keeps its prefix', function (string $driver) {
    $db = adminSite($driver);
    $home = createPage($db, 'en', '', 'Home');
    $about = createPage($db, 'en', 'about', 'About');
    placePage($about, 'About', 'about', $home);
    assertEquals(200, dispatch('/about')->status, 'a child of the home page sits at the top level');

    $usluge = createPage($db, 'hr', 'usluge', 'Usluge');
    $web = createPage($db, 'hr', 'web-dizajn', 'Web dizajn');
    placePage($web, 'Web dizajn', 'web-dizajn', $usluge);
    assertEquals(200, dispatch('/hr/usluge/web-dizajn')->status, 'nested in another language');
    assertMovedTo('/hr/usluge/web-dizajn', dispatch('/hr/web-dizajn'), 'its slug alone, in that language');
    assertEquals(404, dispatch('/usluge/web-dizajn')->status, 'in the main language');
});

testBothDrivers('a page under a parent tells search engines its trail, naming only pages a visitor can open', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', '', 'Home');
    $services = createPage($db, 'en', 'services', 'Services');
    $web = createPage($db, 'en', 'web-design', 'Web </script> design');
    placePage($web, 'Web </script> design', 'web-design', $services);

    $html = dispatch('/services/web-design')->body;
    assertTrue(!str_contains($html, '</script> design'), 'a title closed the script element');
    $trail = breadcrumbsOf($html) ?? fail('no BreadcrumbList on a nested page');
    assertEquals('BreadcrumbList', $trail['@type'] ?? null, 'its type');
    assertEquals([
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'http://example.test/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Services', 'item' => 'http://example.test/services'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Web </script> design', 'item' => 'http://example.test/services/web-design'],
    ], $trail['itemListElement'] ?? null, 'the trail');

    // A page at the top level has nothing to add to its address, and says nothing.
    assertEquals(null, breadcrumbsOf(dispatch('/services')->body), 'a top-level page');

    // An unpublished parent is left out of the trail, though its slug stays in the address.
    App\Modules\Pages\Page::setStatus($db, $services, false);
    $names = array_column(breadcrumbsOf(dispatch('/services/web-design')->body)['itemListElement'] ?? [], 'name');
    assertEquals(['Home', 'Web </script> design'], $names, 'the trail without the draft');
});

testBothDrivers('an address worked out earlier in a request follows a save made later in it', function (string $driver) {
    $db = adminSite($driver);
    $services = createPage($db, 'en', 'services', 'Services');
    $web = createPage($db, 'en', 'web-design', 'Web design');
    Url::usePaths(PagePaths::resolver(static fn () => $db));
    assertEquals('/web-design', Url::page('en', 'web-design'), 'before');

    App\Modules\Pages\Page::update($db, blockRegistry(), $web, ['title' => 'Web design', 'slug' => 'web-design', 'parent_id' => $services, 'status' => 'published', 'seo_json' => '{}'], []);
    assertEquals('/services/web-design', Url::page('en', 'web-design'), 'after, in the same request');
});

test('a loop in the page tree ends instead of never ending', function () {
    $db = installedSite(['en' => 'English'], 'sqlite');
    foreach ([[1, 'a', 2], [2, 'b', 1]] as [$id, $slug, $parent]) {
        $db->query("INSERT INTO pages (id, locale, slug, title, status, parent_id, sort, created_at, updated_at) VALUES (?, 'en', ?, ?, 'published', NULL, 0, '2026-09-26 10:00:00', '2026-09-26 10:00:00')", [$id, $slug, $slug]);
    }
    $db->query('UPDATE pages SET parent_id = 2 WHERE id = 1');
    $db->query('UPDATE pages SET parent_id = 1 WHERE id = 2');
    $paths = PagePaths::all($db);
    assertEquals(['a' => 'b/a', 'b' => 'a/b'], $paths['en'] ?? null, 'each stops at the page that closes the loop');
});
