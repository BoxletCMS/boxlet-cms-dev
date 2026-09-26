<?php

use App\Core\Db;
use App\Core\Response;
use App\Modules\Pages\Page;

/*
 * ADDRESSES THAT USED TO LEAD SOMEWHERE (PLAN.md D-129): a page's old slugs, and rules the
 * owner makes for the addresses of a site this one replaced.
 */

/** A 301 to $location, as a visitor's GET meets it. */
function assertMovedTo(string $location, Response $response, string $what): void
{
    assertEquals(301, $response->status, $what . ': status');
    assertEquals($location, $response->headers['Location'] ?? null, $what . ': where to');
}

/** The editor's save, with only the address changing. */
function renamePage(int $id, string $title, string $slug, string $status = 'published'): void
{
    assertRedirectedTo('/admin/pages/' . $id, adminPost("/admin/pages/{$id}", [
        'title' => $title,
        'slug' => $slug,
        'status' => $status,
        '_end' => '1',
    ]));
}

/** A rule as the owner's screen will store it (step 2), written here directly. */
function addRule(Db $db, string $path, ?int $pageId, ?string $url = null): int
{
    $db->query(
        "INSERT INTO redirects (kind, locale, path, page_id, url, created_at) VALUES ('rule', '', ?, ?, ?, ?)",
        [App\Modules\Redirects\Redirects::normalize(...explode('?', $path, 2)), $pageId, $url, '2026-09-26 10:00:00'],
    );

    return (int) $db->lastInsertId();
}

testBothDrivers('a published page keeps its old addresses, each leading straight to the current one', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'services', 'Services');

    renamePage($id, 'Services', 'what-we-do');
    assertEquals(t('pages.saved_old_address', ['old' => '/services']), $_SESSION['flash'] ?? null, 'the owner is told the old address still works');
    assertMovedTo('/what-we-do', dispatch('/services'), 'the old address');
    assertEquals(200, dispatch('/what-we-do')->status, 'the new one');

    // Renamed again: both old ones go to where it is now, never through each other.
    renamePage($id, 'Services', 'offer');
    assertMovedTo('/offer', dispatch('/services'), 'the first address');
    assertMovedTo('/offer', dispatch('/what-we-do'), 'the second address');

    // Each use counted, for the owner to see which old addresses are still followed.
    $hits = (int) ($db->one("SELECT hits FROM redirects WHERE path = 'services'")['hits'] ?? -1);
    assertEquals(2, $hits, 'uses of the first address');

    // A POST is never sent on: a form cannot be answered by a redirect.
    assertTrue(dispatch('/services', null, 'POST')->status !== 301, 'a POST to an old address was sent on');
});

testBothDrivers('a draft\'s address changes are not kept: nobody outside knew them', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'draft-one', 'Draft', false);

    renamePage($id, 'Draft', 'draft-two', 'draft');
    assertEquals(t('pages.saved'), $_SESSION['flash'] ?? null, 'told of an old address nobody had');
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM redirects')['n'] ?? -1), 'a draft\'s slug kept');
});

testBothDrivers('a page that takes an old address wins it, and a deleted page\'s old addresses answer 404', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'team', 'Team');
    renamePage($id, 'Team', 'people');

    // A new page with the old slug: the live page answers, and the old address is no one's.
    createPage($db, 'en', 'team', 'Team, again');
    assertEquals(200, dispatch('/team')->status, 'the live page');
    assertEquals(0, (int) ($db->one("SELECT COUNT(*) AS n FROM redirects WHERE path = 'team'")['n'] ?? -1), 'the old address still kept');

    // Renamed again, and then deleted: its old address goes with it.
    renamePage($id, 'People', 'crew');
    assertMovedTo('/crew', dispatch('/people'), 'before the delete');
    Page::delete($db, $id);
    assertEquals(404, dispatch('/people')->status, 'an old address of a deleted page');
});

testBothDrivers('an unpublished page is not reached through an old address', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'offer', 'Offer');
    renamePage($id, 'Offer', 'prices');
    Page::setStatus($db, $id, false);

    assertEquals(404, dispatch('/prices')->status, 'its own address');
    assertEquals(404, dispatch('/offer')->status, 'its old address');
});

testBothDrivers('an old address belongs to its language', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'hr', 'usluge', 'Usluge');
    renamePage($id, 'Usluge', 'ponuda');

    assertMovedTo('/hr/ponuda', dispatch('/hr/usluge'), 'in its own language');
    assertEquals(404, dispatch('/usluge')->status, 'in the main language');
});

testBothDrivers('a rule sends an old site\'s address to a page or elsewhere, as typed', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'contact', 'Contact');
    addRule($db, '/Kontakt.html', $id);
    addRule($db, '/index.php?page=12&lang=en', $id);
    addRule($db, '/shop', null, 'https://shop.example.org/');

    assertMovedTo('/contact', dispatch('/kontakt.html'), 'an address with a file name, in any case');
    assertMovedTo('/contact', dispatch('/Kontakt.html?utm_source=news'), 'a rule without a query answers one with a query');
    assertMovedTo('/contact', dispatch('/index.php?lang=en&page=12'), 'its query, in any order');
    assertEquals(404, dispatch('/index.php?page=13&lang=en')->status, 'another query');
    assertMovedTo('https://shop.example.org/', dispatch('/shop/'), 'an address elsewhere');

    // An old WordPress address is the home page's, with a query: the home page asks.
    addRule($db, '/?p=12', $id);
    createPage($db, 'en', '', 'Home');
    assertMovedTo('/contact', dispatch('/?p=12'), 'the home page\'s address with an old query');
    assertEquals(200, dispatch('/?p=13')->status, 'another query is the home page');
    assertEquals(200, dispatch('/')->status, 'and so is no query');

    // The page follows its renames, and when it is deleted the rule stays, pointing nowhere.
    renamePage($id, 'Contact', 'write-to-us');
    assertMovedTo('/write-to-us', dispatch('/kontakt.html'), 'after the page moved');
    Page::delete($db, $id);
    assertEquals(404, dispatch('/kontakt.html')->status, 'a rule whose page is gone');
    $rule = $db->one("SELECT page_id FROM redirects WHERE path = '/kontakt.html'");
    assertTrue($rule !== null && $rule['page_id'] === null, 'the rule the owner typed was removed with the page');
});

test('an address is compared lower case, without its last slash, with its query in one order', function () {
    $normalize = App\Modules\Redirects\Redirects::normalize(...);
    assertEquals('/old/page.html', $normalize('/Old/Page.html/'), 'case and slash');
    assertEquals('/', $normalize('/'), 'the root');
    assertEquals('/index.php?a=1&b=2', $normalize('/index.php', 'b=2&a=1'), 'the query');
});

/*
 * The Redirects screen (D-129, step 2).
 */

testBothDrivers('the owner adds a rule for an old address, to a page or to an address', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'contact', 'Contact');

    // The whole address from the old site, cut to its path and query, in one order.
    assertRedirectedTo('/admin/redirects', adminPost('/admin/redirects', ['from' => 'https://old.example.com/Kontakt.php?b=2&a=1', 'page' => (string) $id, 'url' => '']));
    $rule = $db->one("SELECT path, page_id, url FROM redirects WHERE kind = 'rule'");
    assertEquals(['path' => '/kontakt.php?a=1&b=2', 'page_id' => $id, 'url' => null], $rule === null ? null : ['path' => $rule['path'], 'page_id' => (int) $rule['page_id'], 'url' => $rule['url']], 'what was stored');
    assertMovedTo('/contact', dispatch('/kontakt.php?a=1&b=2'), 'the rule, followed');

    assertRedirectedTo('/admin/redirects', adminPost('/admin/redirects', ['from' => 'shop', 'page' => '', 'url' => 'https://shop.example.org/']));
    assertMovedTo('https://shop.example.org/', dispatch('/shop'), 'a path typed without its slash');

    // Listed, with where each leads and how often it was used.
    $screen = dispatch('/admin/redirects')->body;
    foreach (['/kontakt.php?a=1&amp;b=2', 'Contact', 'https://shop.example.org/', e(t('redirects.used_one', ['count' => '1', 'date' => gmdate('Y-m-d')]))] as $shown) {
        assertContains($shown, $screen, 'the screen shows ' . $shown);
    }
});

testBothDrivers('a rule that could never be used, or leads nowhere safe, is refused in words', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'contact', 'Contact');
    $refused = function (array $body, string $key, array $with = []) use ($db): void {
        $response = adminPost('/admin/redirects', $body + ['from' => '', 'page' => '', 'url' => '']);
        assertEquals(422, $response->status, $key . ': status');
        assertContains(e(t($key, $with)), $response->body, $key . ': said');
        assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM redirects')['n'] ?? -1), $key . ': stored anyway');
    };

    $refused(['from' => '', 'page' => (string) $id], 'redirects.from_required');
    $refused(['from' => '/', 'page' => (string) $id], 'redirects.from_required');
    $refused(['from' => '/admin/pages', 'page' => (string) $id], 'redirects.from_system');
    $refused(['from' => '/Contact/', 'page' => (string) $id], 'redirects.from_live', ['page' => 'Contact']);
    $refused(['from' => '/old.html'], 'redirects.to_required');
    $refused(['from' => '/old.html', 'page' => '999'], 'redirects.to_required');
    $refused(['from' => '/old.html', 'page' => (string) $id, 'url' => 'https://x.example/'], 'redirects.to_both');
    $refused(['from' => '/old.html', 'url' => 'javascript:alert(1)'], 'redirects.url_invalid');
    $refused(['from' => '/old.html', 'url' => '//evil.example/'], 'redirects.url_invalid');

    adminPost('/admin/redirects', ['from' => '/old.html', 'page' => (string) $id, 'url' => '']);
    $response = adminPost('/admin/redirects', ['from' => '/OLD.html', 'page' => (string) $id, 'url' => '']);
    assertContains(e(t('redirects.from_taken')), $response->body, 'the same address twice');
});

testBothDrivers('a rule or a kept old address is deleted from the screen, and then answers 404', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'team', 'Team');
    renamePage($id, 'Team', 'people');
    adminPost('/admin/redirects', ['from' => '/team.html', 'page' => (string) $id, 'url' => '']);

    $screen = dispatch('/admin/redirects')->body;
    assertContains('/team', $screen, 'the kept old address is listed');
    assertContains('/team.html', $screen, 'and the rule');

    foreach ($db->all('SELECT id FROM redirects') as $row) {
        assertRedirectedTo('/admin/redirects', adminPost('/admin/redirects/' . (int) $row['id'] . '/delete', []));
    }
    assertEquals(404, dispatch('/team')->status, 'a deleted kept address');
    assertEquals(404, dispatch('/team.html')->status, 'a deleted rule');
});
