<?php

use App\Core\Db;
use App\Core\Response;
use App\Core\Session;
use App\Modules\Pages\Page;

// Pages CRUD through the admin, against both drivers.

function adminSite(string $driver): Db
{
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski'], $driver);
    createAdmin($db, 'owner@example.com', 'correct horse battery staple');
    $_SESSION['admin_id'] = (int) ($db->one('SELECT id FROM admin')['id'] ?? 0);

    return $db;
}

/**
 * @param array<string, mixed> $body
 */
function adminPost(string $path, array $body): Response
{
    return dispatch($path, null, 'POST', ['_csrf' => (new Session())->csrfToken()] + $body);
}

function assertRedirectedTo(string $location, Response $response): void
{
    if ($response->status !== 302) {
        preg_match_all('~role="alert">([^<]*)<~', $response->body, $alerts);
        fail(sprintf('expected a redirect to %s, got %d: %s', $location, $response->status, html_entity_decode(implode(' | ', $alerts[1]))));
    }
    assertEquals($location, $response->headers['Location'] ?? null, 'Location header');
}

/**
 * @return list<string>
 */
function blockTypes(Db $db, int $pageId): array
{
    return array_map('strval', array_column($db->all('SELECT block_type FROM page_blocks WHERE page_id = ? ORDER BY sort', [$pageId]), 'block_type'));
}

function storedContent(Db $db, int $blockId): mixed
{
    return json_decode((string) ($db->one('SELECT content_json FROM page_blocks WHERE id = ?', [$blockId])['content_json'] ?? ''), true);
}

function templateId(Db $db, string $name): string
{
    return (string) ($db->one('SELECT id FROM templates WHERE name = ?', [$name])['id'] ?? '');
}

testBothDrivers('creating a page from a template pre-fills its blocks', function (string $driver) {
    $db = adminSite($driver);
    $response = adminPost('/admin/pages', ['title' => 'Spring sale', 'locale' => 'en', 'template' => templateId($db, 'landing'), 'slug' => '']);

    $page = $db->one('SELECT id, slug, status, content_group_id FROM pages') ?? fail('no page was created');
    $id = (int) $page['id'];
    assertRedirectedTo('/admin/pages/' . $id, $response);
    assertEquals('spring-sale', $page['slug'], 'slug generated from the title');
    assertEquals('draft', $page['status'], 'status');
    assertEquals($id, (int) $page['content_group_id'], 'content group');
    assertEquals(['hero', 'image_text', 'text'], blockTypes($db, $id), 'blocks from the template');
});

testBothDrivers('a slug of "de" is rejected with a clear message', function (string $driver) {
    $db = adminSite($driver);
    $response = adminPost('/admin/pages', ['title' => 'German', 'locale' => 'en', 'template' => '', 'slug' => 'de']);

    assertEquals(422, $response->status, 'status');
    assertContains(e(t('pages.slug.reserved_language', ['slug' => 'de', 'language' => 'Deutsch'])), $response->body, 'page');
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM pages')['n'] ?? -1), 'pages created');
});

testBothDrivers('the same slug is allowed in two locales but not twice in one', function (string $driver) {
    adminSite($driver);

    assertEquals(302, adminPost('/admin/pages', ['title' => 'About', 'locale' => 'en', 'slug' => 'about'])->status, 'en');
    assertEquals(302, adminPost('/admin/pages', ['title' => 'O nama', 'locale' => 'hr', 'slug' => 'about'])->status, 'hr, same slug');
    $again = adminPost('/admin/pages', ['title' => 'About again', 'locale' => 'en', 'slug' => 'about']);
    assertEquals(422, $again->status, 'en, same slug again');
    assertContains(e(t('pages.slug.taken', ['slug' => 'about'])), $again->body, 'page');
});

testBothDrivers('saving reorders, edits, removes and adds blocks', function (string $driver) {
    $db = adminSite($driver);
    adminPost('/admin/pages', ['title' => 'Spring sale', 'locale' => 'en', 'template' => templateId($db, 'landing')]);
    $id = (int) ($db->one('SELECT id FROM pages')['id'] ?? 0);
    [$hero, $imageText, $text] = array_map('intval', array_column($db->all('SELECT id FROM page_blocks WHERE page_id = ? ORDER BY sort', [$id]), 'id'));

    $response = adminPost("/admin/pages/{$id}", [
        'title' => 'Spring sale',
        'slug' => 'spring-sale',
        'blocks' => [
            ['id' => (string) $text, 'type' => 'text', 'heading' => 'Now first', 'body' => '<p>Moved up</p>'],
            ['id' => (string) $hero, 'type' => 'hero', 'heading' => 'Big news', 'subheading' => '', 'image' => '', 'cta' => ['label' => 'Buy', 'url' => '/buy']],
            ['id' => (string) $imageText, 'type' => 'image_text', 'body' => '', '_delete' => '1'],
            ['type' => 'text', 'heading' => '', 'body' => '<p>Added</p>'],
        ],
        'action' => 'save',
        '_end' => '1',
    ]);

    assertRedirectedTo("/admin/pages/{$id}", $response);
    assertEquals(['text', 'hero', 'text'], blockTypes($db, $id), 'block order');
    $heroContent = storedContent($db, $hero);
    assertEquals('Big news', $heroContent['heading'] ?? null, 'hero heading');
    assertEquals(['label' => 'Buy', 'url' => '/buy'], $heroContent['cta'] ?? null, 'hero button');
    assertEquals(null, $db->one('SELECT id FROM page_blocks WHERE id = ?', [$imageText]), 'removed block');
});

testBothDrivers('a block keeps its type whatever the form claims', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', false, [['type' => 'hero', 'content' => ['heading' => 'Hi']]]);
    $blockId = (string) ($db->one('SELECT id FROM page_blocks')['id'] ?? '');
    $blocks = [['id' => $blockId, 'type' => 'text', 'heading' => 'Still a hero', 'body' => '<p>x</p>']];

    assertRedirectedTo("/admin/pages/{$id}", adminPost("/admin/pages/{$id}", ['title' => 'About', 'slug' => 'about', 'blocks' => $blocks, 'action' => 'save', '_end' => '1']));
    assertEquals(['hero'], blockTypes($db, $id), 'stored type');
});

testBothDrivers('publishing and unpublishing control what visitors see', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'news', 'News', false, [['type' => 'text', 'content' => ['body' => '<p>Hi</p>']]]);
    assertEquals(404, dispatch('/news')->status, 'draft page');

    assertRedirectedTo('/admin/pages', adminPost("/admin/pages/{$id}/status", ['status' => 'published']));
    assertEquals(200, dispatch('/news')->status, 'published page');
    assertTrue(($db->one('SELECT published_at FROM pages')['published_at'] ?? null) !== null, 'published_at is not set');

    adminPost("/admin/pages/{$id}/status", ['status' => 'draft']);
    assertEquals(404, dispatch('/news')->status, 'unpublished page');
});

// Saving a page writes its settings, so it also owns published_at. Stamped the first
// time a page is published and kept from then on, including through an unpublish, so the
// date a page first went live is not rewritten by an edit.
testBothDrivers('publishing stamps published_at once and later saves keep it', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'news', 'News', false, [['type' => 'text', 'content' => ['body' => '<p>Hi</p>']]]);
    $publishedAt = static fn (): ?string => $db->one('SELECT published_at FROM pages WHERE id = ?', [$id])['published_at'] ?? null;
    $save = static fn (string $status) => Page::update($db, blockRegistry(), $id, [
        'title' => 'News',
        'slug' => 'news',
        'parent_id' => null,
        'status' => $status,
    ], []);

    assertEquals(null, $publishedAt(), 'a draft has no published_at');

    $save('published');
    $first = $publishedAt();
    assertTrue($first !== null, 'publishing did not stamp published_at');

    $save('published');
    assertEquals($first, $publishedAt(), 'a later save rewrote published_at');

    $save('draft');
    assertEquals($first, $publishedAt(), 'unpublishing cleared published_at');
    assertEquals('draft', $db->one('SELECT status FROM pages WHERE id = ?', [$id])['status'] ?? null, 'status');
});

testBothDrivers('deleting a page deletes its blocks', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'old', 'Old', true, [['type' => 'text', 'content' => ['body' => '<p>x</p>']]]);

    assertRedirectedTo('/admin/pages', adminPost("/admin/pages/{$id}/delete", []));
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM pages')['n'] ?? -1), 'pages');
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM page_blocks')['n'] ?? -1), 'blocks');
});

test('invalid block content is refused and nothing is saved', function () {
    $db = adminSite('sqlite');
    $id = createPage($db, 'en', 'about', 'About', false, [['type' => 'image_text', 'content' => ['body' => '<p>Kept</p>']]]);
    $blockId = (int) ($db->one('SELECT id FROM page_blocks')['id'] ?? 0);
    $blocks = [['id' => (string) $blockId, 'type' => 'image_text', 'body' => '', 'image_fit' => 'cover', 'link' => ['label' => 'x', 'url' => 'javascript:alert(1)']]];

    $response = adminPost("/admin/pages/{$id}", ['title' => 'Changed', 'slug' => 'about', 'blocks' => $blocks, 'action' => 'save', '_end' => '1']);
    assertEquals(422, $response->status, 'status');
    assertContains(e(t('pages.field.link_url')), $response->body, 'link error');
    assertContains(e(t('pages.field.required')), $response->body, 'required error');
    assertEquals('About', $db->one('SELECT title FROM pages')['title'] ?? null, 'stored title');
    assertEquals('<p>Kept</p>', storedContent($db, $blockId)['body'] ?? null, 'stored body');
});

test('richtext is reduced to the whitelist when saved', function () {
    $db = adminSite('sqlite');
    $id = createPage($db, 'en', 'about', 'About', false, [['type' => 'text', 'content' => ['body' => '<p>x</p>']]]);
    $blockId = (int) ($db->one('SELECT id FROM page_blocks')['id'] ?? 0);
    $body = '<p onclick="steal()">Hi<script>alert(1)</script></p><a href="javascript:alert(1)">link</a>';

    adminPost("/admin/pages/{$id}", ['title' => 'About', 'slug' => 'about', 'blocks' => [['id' => (string) $blockId, 'type' => 'text', 'body' => $body]], 'action' => 'save', '_end' => '1']);
    assertEquals('<p>Hi</p><a>link</a>', storedContent($db, $blockId)['body'] ?? null, 'stored body');
});

test('page admin routes require a login', function () {
    $db = installedSite(['en' => 'English']);
    $id = createPage($db, 'en', 'about', 'About');

    foreach (['/admin/pages', '/admin/pages/new', "/admin/pages/{$id}"] as $path) {
        assertEquals('/admin/login', dispatch($path)->headers['Location'] ?? null, $path);
    }
    assertEquals('/admin/login', adminPost("/admin/pages/{$id}/delete", [])->headers['Location'] ?? null, 'delete');
    assertEquals(1, (int) ($db->one('SELECT COUNT(*) AS n FROM pages')['n'] ?? -1), 'the page still exists');
});
