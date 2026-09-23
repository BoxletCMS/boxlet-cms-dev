<?php

use App\Core\Db;
use App\Modules\Design\SectionStyle;
use App\Modules\Pages\Page;
use App\Modules\Pages\SectionLayout;
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
    assertEquals(SectionLayout::ONE, (string) sectionRowOf($db, $hero)['layout'], 'a migrated section holds one column');
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

/*
 * THE SECTION PANEL IS ROWS OF BUTTONS, NOT LISTS (PLAN.md D-107).
 *
 * A <select> hides its options until pressed, so the one thing an owner wants to know while
 * looking at a page — what else this band could be — cost a click per field. The Appearance
 * screen has answered this since D-065 and the page editor now uses the same control, from
 * the same helper.
 *
 * ASSERTED ON THE MARKUP, not on a stylesheet: what makes this a row of buttons is that the
 * options are radios with the same name, all present at once. The look follows.
 */
testBothDrivers('the section panel offers every closed set as a radio group', function (string $driver) {
    $db = adminSite($driver);
    // With a block, because the fields belong to the band a block stands in.
    $id = createPage($db, 'en', 'panel', 'Panel', true, [['type' => 'text', 'content' => ['body' => '<p>One.</p>']]]);
    $body = dispatch("/admin/pages/{$id}")->body;
    $key = preg_match('~name="sections\[([a-z0-9]+)\]\[layout\]"~', $body, $found) === 1 ? $found[1] : null;
    assertTrue($key !== null, 'the page editor renders no section fields at all');

    $closed = ['layout' => App\Modules\Pages\SectionLayout::LAYOUTS, 'stack' => App\Modules\Pages\SectionLayout::STACKS];
    foreach ($closed as $group => $values) {
        $name = "sections[{$key}][{$group}]";
        assertTrue(
            !str_contains($body, '<select id="section-' . $key . '-' . $group . '"'),
            "the band's {$group} is still a list",
        );
        foreach (array_keys($group === 'layout' ? $values : array_flip($values)) as $value) {
            assertContains(
                'type="radio" id="section-' . $key . '-' . $group . '-' . $value . '" name="' . e($name) . '"',
                $body,
                "{$group} offers no button for {$value}",
            );
        }
    }
    foreach (App\Modules\Design\SectionStyle::OPTIONS as $styleKey => $values) {
        foreach ($values as $value) {
            assertContains(
                'type="radio" id="section-' . $key . '-' . $styleKey . '-' . $value . '"',
                $body,
                "{$styleKey} offers no button for {$value}",
            );
        }
    }

    // And the arrangement is a DIAGRAM: one bar per column, in the proportion declared.
    foreach (App\Modules\Pages\SectionLayout::LAYOUTS as $layout => $weights) {
        $bars = '';
        foreach ($weights as $weight) {
            $bars .= '<i class="w' . $weight . '"></i>';
        }
        assertContains($bars, $body, "the {$layout} arrangement is not drawn in proportion");
    }
});

/*
 * AND EVERY WORD ON A BUTTON FITS ON ONE.
 *
 * "Stack them, top to bottom" is the right sentence in a hint and the wrong one on a control
 * a third of a panel wide. short_label() takes a shorter name where one is written, so this
 * is the guard that one is written wherever it is needed — checked against the values, so a
 * value added to a closed set is caught here rather than by looking at the screen.
 */
test('every value the section panel puts on a button is short enough to read', function () {
    $longest = 14;
    $groups = ['layout' => array_keys(App\Modules\Pages\SectionLayout::LAYOUTS), 'stack' => App\Modules\Pages\SectionLayout::STACKS];
    foreach (App\Modules\Design\SectionStyle::OPTIONS as $styleKey => $values) {
        $groups[$styleKey] = $values;
    }
    $long = [];
    foreach ($groups as $group => $values) {
        foreach ($values as $value) {
            // The arrangements wear their notation, which the outline already uses.
            $label = $group === 'layout' ? t('style.layout.short.' . $value) : short_label('style.' . $group, $value);
            if (mb_strlen($label) > $longest) {
                $long[] = "style.{$group}.{$value} = \"{$label}\"";
            }
        }
    }
    assertEquals([], $long, "values with no short label, over {$longest} characters on a button");
});
