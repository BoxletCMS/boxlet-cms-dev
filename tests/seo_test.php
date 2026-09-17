<?php

use App\Core\Db;
use App\Modules\Pages\Page;

// What a page says about itself in <head>: a meta title and a description (PLAN.md
// D-004). Two fields and no more — no sharing image, no robots directive, no sitemap.

/**
 * A save as either editor makes it, with the two fields under test merged in.
 *
 * _end is not decoration. Without it the route calls the form truncated and refuses
 * before saving anything, which would make every assertion below pass for the wrong
 * reason: nothing written, so nothing overwritten either.
 *
 * @param array<string, mixed> $fields
 */
function savePageWith(int $id, array $fields): void
{
    assertRedirectedTo('/admin/pages/' . $id, adminPost("/admin/pages/{$id}", $fields + [
        'title' => 'About',
        'slug' => 'about',
        'status' => 'published',
        '_end' => '1',
    ]));
}

/**
 * The stored column, not the decoded view of it: some of this is about the shape on
 * disk rather than what a reader ends up with.
 */
function storedSeoJson(Db $db, int $id): string
{
    $row = $db->one('SELECT seo_json FROM pages WHERE id = ?', [$id]);
    if ($row === null) {
        fail("page {$id} is not there");
    }

    return (string) $row['seo_json'];
}

testBothDrivers('a page with no meta of its own falls back to its title and gives no description', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About');

    // create() never writes the column, so a new page holds NULL rather than '{}'.
    // Asserted with a real null check: `?? ` reports a stored null as a missing key.
    $row = $db->one('SELECT seo_json FROM pages WHERE id = ?', [$id]);
    if ($row === null) {
        fail('the page row is not there');
    }
    assertEquals(null, $row['seo_json'], 'a new page stores no seo_json at all');

    $body = dispatch('/about')->body;
    assertContains('<title>About</title>', $body, 'the page title stood in');
    assertTrue(
        !str_contains($body, '<meta name="description"'),
        'a page with no description of its own emitted the tag anyway',
    );
});

testBothDrivers('the two fields are what <head> says', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About');

    savePageWith($id, [
        'seo_title' => 'About our workshop',
        'seo_description' => 'Who we are and what we make.',
    ]);

    $response = dispatch('/about');
    assertEquals(200, $response->status, 'status');
    assertContains('<title>About our workshop</title>', $response->body, 'the meta title replaced the page title');
    assertContains('<meta name="description" content="Who we are and what we make.">', $response->body, 'description');
});

// THE ONE THAT MATTERS. Both editors save through this route, and a form that does not
// carry these two fields is the normal case, not a rare one: every save made before this
// existed, and any form added later. Absent has to mean "leave it alone" — the rule
// parent_id already follows — or each such save quietly erases what the owner wrote.
testBothDrivers('a save from a form that does not carry the two fields keeps what is stored', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About');

    savePageWith($id, [
        'seo_title' => 'About our workshop',
        'seo_description' => 'Who we are and what we make.',
    ]);
    savePageWith($id, []);

    $seo = Page::seo($db->one('SELECT * FROM pages WHERE id = ?', [$id]) ?? []);
    assertEquals('About our workshop', $seo['title'], 'the meta title survived a save without the field');
    assertEquals('Who we are and what we make.', $seo['description'], 'the description survived a save without the field');
});

testBothDrivers('clearing a field is a decision and is saved as one', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About');
    savePageWith($id, [
        'seo_title' => 'About our workshop',
        'seo_description' => 'Who we are and what we make.',
    ]);

    // Sent, and empty: the owner emptied the boxes. Whitespace alone counts as empty.
    savePageWith($id, ['seo_title' => '', 'seo_description' => '   ']);

    assertEquals('{}', storedSeoJson($db, $id), 'an emptied pair is stored as nothing, not as two empty strings');
    $body = dispatch('/about')->body;
    assertContains('<title>About</title>', $body, 'the title fell back again');
    assertTrue(
        !str_contains($body, '<meta name="description"'),
        'the description tag stayed after the field was emptied',
    );
});

test('the editor shows what is stored, never the title it would fall back to', function () {
    $db = adminSite('sqlite');
    $id = createPage($db, 'en', 'about', 'About');

    // An unset meta title is an EMPTY box. Pre-filling it with the page title would make
    // a field the owner set indistinguishable from one they inherited — and the next save
    // would write it back, freezing the title in place from then on.
    assertContains('name="seo_title" value=""', dispatch("/admin/pages/{$id}/form")->body, 'an unset meta title');

    savePageWith($id, [
        'seo_title' => 'About our workshop',
        'seo_description' => 'Who we are and what we make.',
    ]);

    $form = dispatch("/admin/pages/{$id}/form")->body;
    assertContains('name="seo_title" value="About our workshop"', $form, 'the stored meta title');
    assertContains('>Who we are and what we make.</textarea>', $form, 'the stored description');
});

test('a description with quotes and diacritics survives storage and is escaped in the page', function () {
    $db = adminSite('sqlite');
    $id = createPage($db, 'en', 'about', 'About');

    savePageWith($id, ['seo_description' => 'Radionica "Boxlet" — čišćenje i održavanje.']);

    // Stored readable rather than as č escapes: the same encoder flags the rest of
    // the stored JSON uses, so a database someone opens by hand stays legible.
    assertContains('čišćenje', storedSeoJson($db, $id), 'the stored JSON kept the letters');
    assertContains(
        '<meta name="description" content="Radionica &quot;Boxlet&quot; — čišćenje i održavanje.">',
        dispatch('/about')->body,
        'escaped in the page',
    );
});
