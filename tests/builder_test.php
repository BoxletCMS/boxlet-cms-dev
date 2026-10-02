<?php

// The visual editor: a canvas showing the real page, with the block's own fields beside
// it. Most of this screen is browser behaviour the runner cannot reach; what it can test
// is the HTML both halves are built from, and that the save path did not change.

/**
 * @return int the id of a page with three blocks of different types
 */
function builderPage(string $driver = 'sqlite'): int
{
    $db = adminSite($driver);

    return createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'hero', 'content' => ['heading' => 'Welcome']],
        ['type' => 'text', 'content' => ['body' => '<p>Body copy</p>']],
        ['type' => 'image_text', 'content' => ['heading' => 'Beside', 'body' => '<p>More</p>']],
    ]);
}

test('the builder is one shell: bar, rail, canvas and inspector, and its document handed over as JSON', function () {
    $id = builderPage();
    $response = dispatch("/admin/pages/{$id}");

    assertEquals(200, $response->status, 'status');
    assertContains('data-pb ', $response->body, 'the shell');
    foreach (['structure', 'add', 'page'] as $tab) {
        assertContains('data-pb-tab="' . $tab . '"', $response->body, "the rail's {$tab} tab");
    }
    assertContains('data-pb-canvas', $response->body, 'the canvas frame');
    assertContains('data-pb-inspector', $response->body, 'the inspector');
    assertContains('data-pb-nothing', $response->body, 'which starts with nothing selected');

    // No Save button since D-175: the draft saves itself, and the bar says so. Discard and
    // Publish are the bar's only words.
    assertTrue(!str_contains($response->body, e(t('pages.save_draft'))), 'a Save draft button');
    assertContains('data-pb-save', $response->body, 'the save state');
    assertContains('data-pb-publish', $response->body, 'Publish');
    assertContains('data-pb-undo', $response->body, 'undo');
    assertContains('data-pb-redo', $response->body, 'redo');

    // The document, once, as data the page carries: the admin's CSP runs no inline script.
    if (!preg_match('~<script type="application/json" data-pb-data>(.*?)</script>~s', $response->body, $json)) {
        fail('no document handed over');
    }
    $data = json_decode($json[1], true);
    assertEquals(['hero', 'text', 'image_text'], array_column($data['document']['blocks'] ?? [], 'type'), 'every block, in order');
    assertEquals(0, $data['version'] ?? null, 'the draft version it starts from');
    foreach (['draft', 'render', 'inspect', 'fields', 'publish', 'discard', 'restore', 'patterns'] as $endpoint) {
        assertTrue(is_string($data['endpoints'][$endpoint] ?? null), "the {$endpoint} endpoint");
    }

    // Add is a list of icon, name and a line — never a live preview of each block.
    assertEquals(1, substr_count($response->body, '<iframe'), 'the canvas is the one frame on the screen');
    assertContains('data-add-block="hero"', $response->body, 'the Hero in the list');

    // The owner's order (D-176): in the inserter's data as it is, and in Add on its shelves.
    assertEquals(App\Modules\Pages\PageBuilderController::ORDER, array_column($data['library'] ?? [], 'type'), 'the order blocks are offered in');
    preg_match_all('~<h3 class="pb-add-group">([^<]+)</h3>\s*<ul class="pb-add-list">\s*<li><button type="button" class="pb-add-item" data-add-block="([a-z_]+)"~', $response->body, $shelves);
    assertEquals(['Text', 'Media', 'Layout', 'Marketing', 'Embed'], $shelves[1], 'the shelves');
    assertEquals(['text', 'image_text', 'cards', 'hero', 'embed'], $shelves[2], 'each opening with its first in the order');
    // A new block says what each part is for (D-176).
    $hero = array_values(array_filter($data['library'], static fn (array $i): bool => $i['type'] === 'hero'))[0];
    assertEquals('The line that says what this is', $hero['fresh']['heading'] ?? null, 'a sample, not an empty block');
    // Nothing of the owner's yet: a card saying so, not a line.
    assertContains('data-pb-mine-none><div class="pb-add-item pb-add-empty">', $response->body, 'an empty My patterns as a card');
    // Under 800px, the way to the plain editor.
    assertContains('class="pb-small-note"><a href="/admin/pages/' . $id . '/form">', $response->body, 'the small screen\'s line');

    // The document's keys are the browser's to pair by, never a person's to read.
    $words = strip_tags((string) preg_replace('~<(script|template)\b.*?</\1>~s', '', $response->body));
    assertTrue(preg_match('~\b[bsnm][0-9]+\b~', $words) !== 1, 'an internal key is shown: ' . (preg_match('~.{20}\b[bsnm][0-9]+\b.{20}~', $words, $m) ? $m[0] : ''));
});

test('the visual editor links the fallback, and the fallback links back', function () {
    $id = builderPage();

    assertContains('href="/admin/pages/' . $id . '/form"', dispatch("/admin/pages/{$id}")->body, 'link to the fallback');
    assertContains('href="/admin/pages/' . $id . '"', dispatch("/admin/pages/{$id}/form")->body, 'link to the visual editor');
});

test('the canvas renders the real page, with sections as direct children of main', function () {
    $id = builderPage();
    $response = dispatch("/admin/pages/{$id}/canvas");

    assertEquals(200, $response->status, 'status');
    // sections.css styles a section by its position among its siblings, so anything
    // inserted between main and a section would change the page being judged.
    //
    /* The band carries data-bx-section before its class since D-099 — the editor's one
       addition to the visitor's markup, so the + in an empty column can say which band it
       is aiming at. And since D-103 the editor's canvas always draws the column shape, so
       the hero's own classes sit on a div inside the band rather than on the band itself:
       a column is what a block is dragged into, and a band that draws none has nowhere to
       drop one. What this asserts is unchanged — the FIRST thing inside main is a section,
       and the hero is what stands in it. */
    assertTrue(
        (bool) preg_match('~<main data-bx-blocks>\s*<section [^>]*class="block ~', $response->body),
        'the first section is not a direct child of main',
    );
    /* The block carries its key before its class since D-117, the band's rule applied to
       the block: `b<id>`, the same name its field group has in the panel, which is the only
       thing the two sides may pair by. What this asserted before is unchanged — the hero
       stands in a column of the first band — and the key is asserted apart, beside it. */
    assertTrue(
        (bool) preg_match('~<div class="section-column">\s*<div [^>]*class="block-hero ~', $response->body),
        'the hero is not standing in a column of the first band',
    );
    assertTrue(
        (bool) preg_match('~<div class="section-column">\s*<div data-bx-key="b[0-9]+" class="block-hero ~', $response->body),
        'the hero does not carry the key its field group has, so the editor would pair them by counting',
    );
    assertTrue(
        // Its KEY and not its id — `s8` for a stored band, `m0` for one made in this
        // session (D-098). The canvas has to name a band that may not be saved yet.
        (bool) preg_match('~<section data-bx-section="[sm][0-9]+" class="block surface-~', $response->body),
        'the band does not say which band it is, so an empty column could not be aimed at',
    );
    assertContains('Welcome', $response->body, 'block content');
    assertContains('<p>Body copy</p>', $response->body, 'rich text');
    assertTrue(!str_contains($response->body, 'admin-bar'), 'the canvas carries admin chrome');
});

test('the canvas is the one admin document that renders with the site design', function () {
    $id = builderPage();
    $canvas = dispatch("/admin/pages/{$id}/canvas");
    $shell = dispatch("/admin/pages/{$id}");

    // The canvas is the site, so it links the site's compiled tokens and stylesheets.
    assertTrue((bool) preg_match('~/cache/tokens\.[0-9a-f]{12}\.css~', $canvas->body), 'canvas tokens');
    assertContains('assets/site.css', $canvas->body, 'canvas site styles');
    assertContains('assets/canvas.css', $canvas->body, 'canvas editor chrome');
    // And no script: the builder draws over it from the parent (D-175).
    assertTrue(!str_contains($canvas->body, '<script'), 'the canvas runs a script of its own');

    // The shell around it is the admin, so it links none of them.
    assertTrue(!str_contains($shell->body, '/cache/tokens.'), 'the shell links the site design');
    assertContains('assets/admin.css', $shell->body, 'shell admin styles');
});

test('the canvas may be framed by the admin and by nobody else', function () {
    $id = builderPage();
    $canvas = dispatch("/admin/pages/{$id}/canvas");

    assertContains("frame-ancestors 'self'", $canvas->headers['Content-Security-Policy'] ?? '', 'CSP');
    assertEquals('SAMEORIGIN', $canvas->headers['X-Frame-Options'] ?? null, 'X-Frame-Options');
});

// The one frame of another site's the canvas may hold is an OpenStreetMap map, the only embed
// drawn before a press (D-147, D-148). A video is a link there, and nothing else is framed.
test('the canvas frames OpenStreetMap and nothing else of another site\'s', function () {
    $csp = dispatch('/admin/pages/' . builderPage() . '/canvas')->headers['Content-Security-Policy'] ?? '';

    assertContains('frame-src https://www.openstreetmap.org;', $csp, 'the map');
    assertTrue(!str_contains($csp, 'youtube') && !str_contains($csp, 'google') && !str_contains($csp, 'vimeo'), 'another provider may be framed');
});

test('the visual editor and the canvas require an admin session, and refuse a missing page', function () {
    $id = builderPage();
    $_SESSION = [];

    foreach (["/admin/pages/{$id}", "/admin/pages/{$id}/canvas", "/admin/pages/{$id}/form"] as $path) {
        assertEquals('/admin/login', dispatch($path)->headers['Location'] ?? null, $path);
    }

    adminSite('sqlite');
    assertEquals(404, dispatch('/admin/pages/9999/canvas')->status, 'canvas of a page that does not exist');
});

// THE PAGE TAB (D-175): title, address and parent are fields of the document the browser
// holds, saved with the draft and checked when it is published.

testBothDrivers('the page tab\'s parent travels with the draft, and Publish puts the page on the site', function (string $driver) {
    $db = adminSite($driver);
    $parent = createPage($db, 'en', 'about', 'About', true);
    $id = createPage($db, 'en', 'team', 'Team', false, [['type' => 'text', 'content' => ['body' => '<p>x</p>']]]);
    [$document] = builderBand($id);
    $document['parent_id'] = $parent;
    assertEquals(200, draftRequest($id, ['version' => 0, 'document' => $document])->status, 'saved');
    assertEquals(null, $db->one('SELECT parent_id FROM pages WHERE id = ?', [$id])['parent_id'] ?? null, 'a draft is not the page');

    assertEquals(200, builderRequest("/admin/pages/{$id}/publish", [])->status, 'published');
    $row = $db->one('SELECT parent_id, status, published_at FROM pages WHERE id = ?', [$id]) ?? [];
    assertEquals($parent, (int) ($row['parent_id'] ?? 0), 'stored parent');
    assertEquals('published', $row['status'] ?? null, 'on the site');
    assertTrue(($row['published_at'] ?? null) !== null, 'published_at was not stamped');
});

// An empty address means "the home page of this language": a page that already has one
// refuses it, and Publish says which field is wrong.
test('an address that collides is refused at Publish, named by its field', function () {
    $db = adminSite('sqlite');
    createPage($db, 'en', '', 'Home', true);
    $id = createPage($db, 'en', 'about', 'About', true, [['type' => 'text', 'content' => ['body' => '<p>Kept</p>']]]);
    [$document] = builderBand($id);
    $document['slug'] = '';
    draftRequest($id, ['version' => 0, 'document' => $document]);

    $refused = builderRequest("/admin/pages/{$id}/publish", []);
    assertEquals(422, $refused->status, 'status');
    assertEquals(t('pages.slug.home_taken'), json_decode($refused->body, true)['errors']['slug'] ?? null, 'the reason, by the field it is about');
    assertEquals('about', $db->one('SELECT slug FROM pages WHERE id = ?', [$id])['slug'] ?? null, 'the stored address');
});

// The select never offers a parent that would make a cycle. This is the request that
// does not come from the select.
test('a parent that would make a cycle is refused however the request arrives', function () {
    $db = adminSite('sqlite');
    $about = createPage($db, 'en', 'about', 'About', true, [['type' => 'text', 'content' => ['body' => '<p>x</p>']]]);
    $team = createPage($db, 'en', 'team', 'Team', true);
    $db->query('UPDATE pages SET parent_id = ? WHERE id = ?', [$about, $team]);
    $blockId = (string) ($db->one('SELECT id FROM page_blocks WHERE page_id = ?', [$about])['id'] ?? '');

    // Through the plain form...
    $response = adminPost("/admin/pages/{$about}", [
        'title' => 'About',
        'slug' => 'about',
        'parent_id' => (string) $team,
        'blocks' => [['id' => $blockId, 'type' => 'text', 'heading' => '', 'body' => '<p>x</p>']],
        'action' => 'publish',
        '_end' => '1',
    ]);
    assertEquals(422, $response->status, 'status');
    assertContains(e(t('pages.parent_invalid')), $response->body, 'the reason is on screen');

    // ...and through the builder's draft and Publish.
    [$document] = builderBand($about);
    $document['parent_id'] = $team;
    draftRequest($about, ['version' => 0, 'document' => $document]);
    assertEquals(422, builderRequest("/admin/pages/{$about}/publish", [])->status, 'published under its own subpage');
    assertEquals(null, $db->one('SELECT parent_id FROM pages WHERE id = ?', [$about])['parent_id'] ?? null, 'stored parent');
});

test('the page tab carries the address, the parent and the search words as fields bound to the document', function () {
    $db = adminSite('sqlite');
    createPage($db, 'en', '', 'Home', true);
    $id = createPage($db, 'en', 'about', 'About', false);
    $body = dispatch("/admin/pages/{$id}")->body;

    assertContains('data-pb-page="slug" value="about"', $body, 'the address');
    assertContains('data-pb-slug-hint', $body, 'the line that says it follows the title');
    foreach (['title', 'parent_id', 'seo_title', 'seo_description', 'noindex'] as $field) {
        assertContains('data-pb-page="' . $field . '"', $body, $field);
    }
    assertContains('class="pb-serp"', $body, 'the picture of a search result');
    assertTrue(!str_contains($body, 'name="status"'), 'no visibility select: Publish is how a page goes live');
    // Home is a valid parent for About; About must not be offered itself.
    $parent = preg_match('~<select id="pb-parent" data-pb-page="parent_id">(.*?)</select>~s', $body, $match) === 1 ? $match[1] : '';
    assertContains('>Home</option>', $parent, 'another page as a parent');
    assertTrue(!str_contains($parent, '>About</option>'), 'the page was offered itself as its parent');
});

test('a rejected save of the plain form comes back in the plain form, and stores nothing', function () {
    $db = adminSite('sqlite');
    $id = createPage($db, 'en', 'about', 'About', false, [['type' => 'text', 'content' => ['body' => '<p>Kept</p>']]]);
    $blockId = (string) ($db->one('SELECT id FROM page_blocks')['id'] ?? '');
    $body = ['title' => '', 'slug' => 'about', 'blocks' => [['id' => $blockId, 'type' => 'text', 'body' => '']], 'action' => 'publish', '_end' => '1'];

    $fromForm = adminPost("/admin/pages/{$id}", $body);
    assertEquals(422, $fromForm->status, 'status');
    assertContains('<template data-block-template=', $fromForm->body, 'the plain form came back');
    assertContains(e(t('pages.title_required')), $fromForm->body, 'the error');
    assertEquals('<p>Kept</p>', storedContent($db, (int) $blockId)['body'] ?? null, 'nothing was stored');
});

test('guard (source, not behaviour): the Structure tree is drawn from the document, with no key a person reads', function () {
    // The tree is a third view of the document the browser holds (D-175): drawn from it,
    // never fetched, and named in words — a band by its name or "Section N", a block by its
    // type's name — while the keys stay in attributes.
    $script = (string) file_get_contents(dirname(__DIR__) . '/public/assets/builder-tree.js');
    assertContains('pb.doc.sections', $script, 'the tree does not read the document');
    assertTrue(!str_contains($script, 'fetch('), 'the tree asks the server for the page shape');
    assertContains('pb.sectionName(', $script, 'a band is not named in words');
});
