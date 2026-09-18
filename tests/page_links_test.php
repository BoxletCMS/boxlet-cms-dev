<?php

use App\Core\Db;
use App\Modules\Pages\BlockForm;
use App\Modules\Pages\PageLinks;
use App\Modules\Settings\SiteChrome;
use App\Support\RichText;
use App\Support\SafeUrl;

// A link points at a page, not at a typed path (PLAN.md D-034).
//
// Stored as page:{group} in a link field's url and in a rich text href; followed at render
// in the visitor's language; drawn as no link at all when the page is deleted, is a draft,
// or has no version in that language. Asserted on the served HTML wherever it can be,
// because a reference resolved correctly and never emitted is the same as a broken link.

/**
 * A page holding one hero whose button, and one text block whose words, link to $target.
 */
function linkingPage(Db $db, string $locale, string $slug, int $target, string $label = 'Read more'): int
{
    return createPage($db, $locale, $slug, 'Linking page', true, [
        ['type' => 'hero', 'content' => [
            'heading' => 'Hello',
            'cta' => ['label' => $label, 'url' => PageLinks::to($target)],
        ]],
        ['type' => 'text', 'content' => [
            'heading' => 'Words',
            'body' => '<p>Go to <a href="' . PageLinks::to($target) . '">our <strong>studio</strong></a> today.</p>',
        ]],
    ]);
}

test('a page reference is a link that may be stored, and nothing else is let in with it', function () {
    assertTrue(SafeUrl::isLink('page:12'), 'page:12 refused');
    assertTrue(SafeUrl::isLink('/about'), 'a typed path refused');
    foreach (['page:0', 'page:', 'page:12x', 'page:-1', 'page: 12', 'PAGE:12x', 'page:12345678901'] as $bad) {
        assertTrue(!SafeUrl::isLink($bad), "{$bad} accepted");
    }
    // A menu item points at a page through its own column; its typed address stays typed.
    assertTrue(!SafeUrl::isAllowed('page:12'), 'isAllowed() took a page reference');

    assertEquals('<p><a href="page:7">x</a></p>', RichText::sanitize('<p><a href="page:7">x</a></p>'), 'the sanitiser dropped a page reference');
    assertEquals('<p><a>x</a></p>', RichText::sanitize('<p><a href="page:0">x</a></p>'), 'a malformed reference survived');
});

testBothDrivers('a link to a page follows it, and moves when its address changes', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $about = createPage($db, 'en', 'about', 'About us');
    linkingPage($db, 'en', 'start', $about);

    $body = dispatch('/start')->body;
    assertContains('href="/about">Read more</a>', $body, 'the button does not lead to the page');
    assertContains('<a href="/about">our <strong>studio</strong></a>', $body, 'the rich text link does not lead to the page');
    assertTrue(!str_contains($body, 'page:'), 'a reference reached the visitor as written');

    $db->query('UPDATE pages SET slug = ? WHERE id = ?', ['who-we-are', $about]);
    $body = dispatch('/start')->body;
    assertContains('href="/who-we-are">Read more</a>', $body, 'the button kept the old address');
    assertContains('<a href="/who-we-are">our', $body, 'the rich text link kept the old address');
});

testBothDrivers('a link to a draft or a deleted page is drawn as no link at all', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $about = createPage($db, 'en', 'about', 'About us', false);
    linkingPage($db, 'en', 'start', $about);

    $body = dispatch('/start')->body;
    assertTrue(!str_contains($body, 'Read more'), 'a button to a draft was drawn');
    assertContains('Go to our <strong>studio</strong> today.', $body, 'the words of a link to a draft were not kept as text');
    assertTrue(!str_contains($body, '<a href="/about"'), 'a draft was linked');

    $db->query('DELETE FROM pages WHERE id = ?', [$about]);
    $body = dispatch('/start')->body;
    assertTrue(!str_contains($body, 'Read more'), 'a button to a deleted page was drawn');
    assertContains('Go to our <strong>studio</strong> today.', $body, 'the words of a link to a deleted page were lost');
});

testBothDrivers('an empty label takes the page title', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $about = createPage($db, 'en', 'about', 'About us');
    linkingPage($db, 'en', 'start', $about, '');

    assertContains('href="/about">About us</a>', dispatch('/start')->body, 'the page title did not stand in for the label');
});

testBothDrivers('a reference follows the visitor into their language', function (string $driver) {
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski'], $driver);
    $about = createPage($db, 'en', 'about', 'About us');
    $onama = createPage($db, 'hr', 'o-nama', 'O nama');
    // A translation shares its source's group (SPEC §5.2).
    $db->query('UPDATE pages SET content_group_id = ? WHERE id = ?', [$about, $onama]);
    // The Croatian copy of a block carries the same reference, verbatim.
    linkingPage($db, 'hr', 'pocetak', $about);

    $body = dispatch('/hr/pocetak')->body;
    assertContains('href="/hr/o-nama">Read more</a>', $body, 'the Croatian button did not lead to the Croatian page');

    // No Croatian version of the page: no link, rather than a link into another language.
    $db->query('DELETE FROM pages WHERE id = ?', [$onama]);
    assertTrue(!str_contains(dispatch('/hr/pocetak')->body, 'Read more'), 'a link crossed into another language');
});

test('references inside repeater items are found and followed', function () {
    $fields = [
        'items' => ['type' => 'repeater', 'max' => 3, 'fields' => [
            'link' => ['type' => 'link', 'required' => false, 'translatable' => true],
            'body' => ['type' => 'richtext', 'required' => false, 'translatable' => true],
        ]],
    ];
    $content = ['items' => [
        ['link' => ['label' => 'One', 'url' => 'page:3'], 'body' => '<p><a href="page:4">four</a></p>'],
        ['link' => ['label' => 'Two', 'url' => '/typed'], 'body' => ''],
    ]];

    assertEquals([3, 4], PageLinks::groupsIn($fields, $content), 'the groups inside items');

    $resolved = PageLinks::apply($fields, $content, [3 => ['url' => '/three', 'title' => 'Three']]);
    assertEquals(
        ['items' => [
            ['link' => ['label' => 'One', 'url' => '/three'], 'body' => '<p>four</p>'],
            ['link' => ['label' => 'Two', 'url' => '/typed'], 'body' => ''],
        ]],
        $resolved,
        'items after following',
    );
});

test('the link field stores a chosen page, and asks for text only for an address', function () {
    $registry = blockRegistry();
    $parse = static fn (array $cta): array => BlockForm::parse($registry, [
        ['type' => 'hero', 'heading' => 'Hi', 'cta' => $cta],
    ], []);

    // A page chosen wins over whatever the hidden address input still holds.
    $parsed = $parse(['page' => '12', 'url' => '/stale', 'label' => '']);
    assertEquals([], $parsed['errors'], 'a page with no label was refused');
    assertEquals(['label' => '', 'url' => 'page:12'], $parsed['blocks'][0]['content']['cta'] ?? null, 'what was stored');

    $parsed = $parse(['page' => '', 'url' => '/contact', 'label' => '']);
    assertEquals(t('pages.field.link_label'), $parsed['errors']['0.cta'] ?? null, 'an address with no label was let through');

    $parsed = $parse(['page' => 'nope', 'url' => 'javascript:alert(1)', 'label' => 'x']);
    assertEquals(t('pages.field.link_url'), $parsed['errors']['0.cta'] ?? null, 'a bad address was let through');
});

testBothDrivers('the header button can point at a page', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    createPage($db, 'en', '', 'Home');
    $contact = createPage($db, 'en', 'contact', 'Contact');
    SiteChrome::saveForLocale($db, 'en', ['button_label' => '', 'button_url' => PageLinks::to($contact), 'text' => '', 'small_print' => '']);

    assertContains('href="/contact">Contact</a>', dispatch('/')->body, 'the header button did not lead to the page');
});

testBothDrivers('the editor offers pages of the page\'s own language, drafts marked', function (string $driver) {
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski'], $driver);
    $home = createPage($db, 'en', '', 'Home');
    $draft = createPage($db, 'en', 'soon', 'Coming soon', false);
    createPage($db, 'hr', 'o-nama', 'O nama');

    $choices = PageLinks::choices($db, 'en');
    assertEquals([$home, $draft], array_keys($choices), 'the pages offered');
    assertTrue($choices[$home]['published'] && !$choices[$draft]['published'], 'the draft is not marked');
});
