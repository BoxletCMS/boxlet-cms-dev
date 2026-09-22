<?php

use App\Modules\Languages\Locales;
use App\Modules\Pages\Page;
use App\Modules\Pages\Translations;
use App\Modules\Pages\TranslationStatus;

// A translation's blocks behind their source (SPEC §8 Slice 6's acceptance, PLAN.md D-043,
// step 3). adminSite() gives English as the main language and Croatian beside it.

/**
 * Changes one field of one block of a page through the model's own save, the path the
 * editors take, so whatever a save does to translation state is part of what is tested.
 *
 * @param array<string, mixed> $change
 */
function editBlock(App\Core\Db $db, int $pageId, int $position, array $change): void
{
    $page = Page::find($db, $pageId) ?? fail("no page {$pageId}");
    $blocks = Page::editable($db, blockRegistry(), $pageId);
    $block = $blocks[$position] ?? fail("no block at {$position}");
    $block['content'] = $change + ($block['content'] ?? []);
    $blocks[$position] = $block;
    Page::update($db, blockRegistry(), $pageId, [
        'title' => (string) $page['title'],
        'slug' => (string) $page['slug'],
        'parent_id' => null,
        'status' => (string) $page['status'],
        'seo_json' => (string) ($page['seo_json'] ?? '{}'),
    ], $blocks);
}

/**
 * A Croatian page of three blocks, translated into English and German.
 *
 * @return array{int, int, int} the source's id, the English one's and the German one's
 */
function croatianWithTwoTranslations(App\Core\Db $db): array
{
    Locales::add($db, 'de');
    $source = createPage($db, 'hr', 'o-nama', 'O nama', true, [
        ['type' => 'hero', 'content' => ['heading' => 'Dobro došli']],
        ['type' => 'text', 'content' => ['heading' => 'Tko smo', 'body' => '<p>Mali studio.</p>']],
        ['type' => 'text', 'content' => ['heading' => 'Gdje smo', 'body' => '<p>Zagreb.</p>']],
    ]);

    return [
        $source,
        (int) Translations::create($db, blockRegistry(), $source, 'en'),
        (int) Translations::create($db, blockRegistry(), $source, 'de'),
    ];
}

testBothDrivers('editing one block of the source marks only that block, in every translation', function (string $driver) {
    $db = adminSite($driver);
    [$source, $english, $german] = croatianWithTwoTranslations($db);
    assertEquals([], TranslationStatus::of($db, blockRegistry(), $english)['stale'], 'stale before anything changed');

    editBlock($db, $source, 1, ['body' => '<p>Mali studio, od 2019.</p>']);

    foreach (['English' => $english, 'German' => $german] as $name => $translation) {
        $stale = TranslationStatus::of($db, blockRegistry(), $translation)['stale'];
        // The second block as the PAGE draws it: since D-095 a block's own sort is its
        // place inside its section, and the section carries the page's order.
        $second = blockIdsInOrder($db, $translation)[1] ?? 0;
        assertEquals([$second], array_keys($stale), "{$name}: the stale blocks");

        // On the canvas, exactly one section carries the mark; in the inspector, one notice.
        assertEquals(1, substr_count(dispatch("/admin/pages/{$translation}/canvas")->body, 'data-bx-stale'), "{$name}: marked sections");
        $builder = dispatch("/admin/pages/{$translation}")->body;
        assertEquals(1, substr_count($builder, 'form="current-'), "{$name}: stale notices");
        assertContains('Mali studio, od 2019.', $builder, "{$name}: what the original says now");
    }
    // The source itself is never behind anything.
    assertEquals([], TranslationStatus::of($db, blockRegistry(), $source)['stale'], 'the source');
});

testBothDrivers('a picture changed in the source is not something a translation falls behind on', function (string $driver) {
    $db = adminSite($driver);
    [$source, $english] = croatianWithTwoTranslations($db);
    $picture = storedPicture($db, 'harbour', []);

    editBlock($db, $source, 0, ['image' => $picture]);
    assertEquals([], TranslationStatus::of($db, blockRegistry(), $english)['stale'], 'stale after a picture changed');
});

testBothDrivers('marking a block current clears it, and only a block of that page can be marked', function (string $driver) {
    $db = adminSite($driver);
    [$source, $english] = croatianWithTwoTranslations($db);
    editBlock($db, $source, 2, ['heading' => 'Gdje nas naći']);
    $block = array_key_first(TranslationStatus::of($db, blockRegistry(), $english)['stale']);

    assertRedirectedTo("/admin/pages/{$english}", adminPost("/admin/pages/{$english}/blocks/{$block}/current", []));
    assertEquals([], TranslationStatus::of($db, blockRegistry(), $english)['stale'], 'still stale after marking');
    assertContains(e(t('translations.marked_current')), dispatch("/admin/pages/{$english}")->body, 'what happened is said');

    // A block of another page, named in this page's address.
    $foreign = (int) ($db->one('SELECT id FROM page_blocks WHERE page_id = ?', [$source])['id'] ?? 0);
    adminPost("/admin/pages/{$english}/blocks/{$foreign}/current", []);
    assertContains(e(t('translations.not_a_block')), dispatch("/admin/pages/{$english}")->body, 'a block of another page');
});

testBothDrivers('a block added to the source is counted as missing from the translation', function (string $driver) {
    $db = adminSite($driver);
    [$source, $english] = croatianWithTwoTranslations($db);
    $blocks = Page::editable($db, blockRegistry(), $source);
    $blocks[] = ['key' => 'n0', 'id' => null, 'type' => 'text', 'content' => blockRegistry()->normalize('text', ['body' => '<p>Novo.</p>']), 'style' => [], 'layout' => 'single'];
    Page::update($db, blockRegistry(), $source, ['title' => 'O nama', 'slug' => 'o-nama', 'parent_id' => null, 'status' => 'published', 'seo_json' => '{}'], $blocks);

    assertEquals(1, TranslationStatus::of($db, blockRegistry(), $english)['missing'], 'missing blocks');
    assertContains(html_entity_decode(e(t('translations.missing', ['count' => '1', 'language' => 'Hrvatski']))), html_entity_decode(dispatch("/admin/pages/{$english}")->body), 'the notice');
});

testBothDrivers('the pages list shows how many blocks of a translation changed in its original', function (string $driver) {
    $db = adminSite($driver);
    [$source, $english] = croatianWithTwoTranslations($db);
    editBlock($db, $source, 0, ['heading' => 'Dobro nam došli']);
    editBlock($db, $source, 1, ['heading' => 'Tko smo mi']);

    assertEquals([$english => 2], array_intersect_key(TranslationStatus::counts($db, blockRegistry()), [$english => true]), 'counts');
    assertContains(e(t('translations.stale_badge', ['count' => '2'])), dispatch('/admin/pages')->body, 'the badge');
});

test('the original\'s words are shown as text, with their fields named', function () {
    $words = TranslationStatus::words(blockRegistry(), 'columns', [
        'heading' => 'Naslov',
        'intro' => '',
        'items' => [['heading' => 'Prvi', 'body' => '<p>Jedan <strong>dva</strong></p>', 'image' => 4, 'link' => ['label' => 'Više', 'url' => '/x']]],
        'image_shape' => 'wide',
    ]);

    assertEquals([
        ['label' => t('block.columns.heading'), 'text' => 'Naslov'],
        ['label' => t('pages.field.repeater_item', ['number' => '1']) . ' · ' . t('block.columns.items.heading'), 'text' => 'Prvi'],
        ['label' => t('pages.field.repeater_item', ['number' => '1']) . ' · ' . t('block.columns.items.body'), 'text' => 'Jedan dva'],
        ['label' => t('pages.field.repeater_item', ['number' => '1']) . ' · ' . t('block.columns.items.link'), 'text' => 'Više'],
    ], $words, 'the words');
});
