<?php

use App\Core\Db;
use App\Modules\Design\SectionStyle;
use App\Modules\Pages\Page;
use App\Modules\Pages\Sections;

/*
 * The section a block sits in, and the layer-2 style it owns (PLAN.md D-095, migration 0026).
 *
 * In this step a section holds exactly one block, so nothing on any screen changes; what
 * these prove is that the style moved without anything being dropped on the way, and that
 * the places which used to read it from the block now read it from the section.
 *
 * adminSite() and adminPost() come from pages_admin_test.php.
 */

/** @return array<string, mixed> */
function sectionRowOf(Db $db, int $blockId): array
{
    $row = $db->one(
        'SELECT s.* FROM page_blocks b JOIN page_sections s ON s.id = b.section_id WHERE b.id = ?',
        [$blockId],
    );

    return $row ?? fail("block {$blockId} has no section");
}

testBothDrivers('every block has a section of its own, carrying its style', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', false, [
        ['type' => 'hero', 'content' => ['heading' => 'Hi'], 'style' => ['surface' => 'contrast', 'rhythm' => 'airy']],
        ['type' => 'text', 'content' => ['body' => '<p>A</p>'], 'style' => ['surface' => 'tinted']],
    ]);

    [$hero, $text] = blockIdsInOrder($db, $id);
    assertEquals('contrast', sectionStyleOf($db, $hero)['surface'] ?? null, 'the hero section');
    assertEquals('tinted', sectionStyleOf($db, $text)['surface'] ?? null, 'the text section');
    assertEquals(2, count(Sections::forPage($db, $id)), 'one section per block');

    // Each has its own row: a style set on one must not reach the other, which is the whole
    // reason the style is not a property of the page.
    assertTrue(sectionRowOf($db, $hero)['id'] !== sectionRowOf($db, $text)['id'], 'two blocks share one section');
    assertEquals(Sections::ONE, (string) sectionRowOf($db, $hero)['layout'], 'a migrated section holds one column');
});

testBothDrivers('the section carries the page order, and the block its place inside it', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', false, [
        ['type' => 'hero', 'content' => ['heading' => 'One']],
        ['type' => 'text', 'content' => ['body' => '<p>Two</p>']],
    ]);
    [$hero, $text] = blockIdsInOrder($db, $id);

    assertEquals(0, (int) sectionRowOf($db, $hero)['sort'], 'the first section');
    assertEquals(1, (int) sectionRowOf($db, $text)['sort'], 'the second section');
    // A block's own sort is its place WITHIN its section, which is 0 while a section holds
    // one. Several tests caught this by failing when it was left carrying the page's order.
    assertEquals(
        [0, 0],
        array_map(
            static fn (int $block): int => (int) ($db->one('SELECT sort FROM page_blocks WHERE id = ?', [$block])['sort'] ?? -1),
            [$hero, $text],
        ),
        'a block sorts within its section',
    );
});

testBothDrivers('a removed block takes its section with it', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', false, [
        ['type' => 'hero', 'content' => ['heading' => 'One']],
        ['type' => 'text', 'content' => ['body' => '<p>Two</p>']],
    ]);
    assertEquals(2, count(Sections::forPage($db, $id)), 'sections before');

    $page = Page::find($db, $id) ?? fail('no page');
    $blocks = Page::editable($db, blockRegistry(), $id);
    Page::update($db, blockRegistry(), $id, [
        'title' => (string) $page['title'], 'slug' => (string) $page['slug'],
        'parent_id' => null, 'status' => 'draft', 'seo_json' => '{}',
    ], [$blocks[0]]);

    // A section left holding nothing would go on drawing its surface and its rhythm around
    // an empty container.
    assertEquals(1, count(Sections::forPage($db, $id)), 'the empty section was left behind');
    assertEquals(1, count(blockIdsInOrder($db, $id)), 'blocks after');
});

testBothDrivers('a deleted page takes its sections with it', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', false, [['type' => 'text', 'content' => ['body' => '<p>A</p>']]]);
    assertEquals(1, count(Sections::forPage($db, $id)), 'a section to delete');

    $db->query('DELETE FROM pages WHERE id = ?', [$id]);

    assertEquals(
        0,
        (int) ($db->one('SELECT COUNT(*) AS n FROM page_sections WHERE page_id = ?', [$id])['n'] ?? -1),
        'sections left behind by a deleted page',
    );
});

testBothDrivers('a translation gets sections of its own, not the source\'s', function (string $driver) {
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski'], $driver);
    $source = createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'hero', 'content' => ['heading' => 'Hi'], 'style' => ['surface' => 'contrast', 'divider' => 'slant']],
    ]);
    $translation = (int) App\Modules\Pages\Translations::create($db, blockRegistry(), $source, 'hr');

    $from = Sections::forPage($db, $source);
    $to = Sections::forPage($db, $translation);
    assertEquals(count($from), count($to), 'sections copied');
    assertEquals(array_values(array_map(static fn (array $s): array => $s['style'], $from)),
        array_values(array_map(static fn (array $s): array => $s['style'], $to)), 'the style came with it');
    // Two locales sharing a section row would mean restyling one restyles the other.
    assertEquals([], array_intersect(array_keys($from), array_keys($to)), 'the locales share a section row');
});

test('guard (source, not behaviour): nothing reads the style off a block any more', function () {
    // Migration 0026 emptied page_blocks.style_json and left the column in place, because a
    // committed migration is not edited. What stops a reader coming back is this: the only
    // place the two may be named together is the one INSERT that writes '{}'.
    $root = dirname(__DIR__);
    $offenders = [];
    foreach (glob($root . '/app/**/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);
        if (!str_contains($source, 'page_blocks')) {
            continue;
        }
        foreach (explode(';', $source) as $statement) {
            if (!str_contains($statement, 'page_blocks') || !str_contains($statement, 'style_json')) {
                continue;
            }
            if (str_contains($statement, "'{}'")) {
                continue; // the write that keeps the NOT NULL column satisfied
            }
            $offenders[] = str_replace($root . '/', '', $file);
        }
    }

    assertEquals([], array_values(array_unique($offenders)), 'files reading page_blocks.style_json');
});
