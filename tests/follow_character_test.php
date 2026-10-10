<?php

use App\Modules\Design\Composition;
use App\Modules\Design\Presets;

/*
 * A BLOCK'S LAYOUT MAY FOLLOW THE CHARACTER (PLAN.md D-191, the owner). '' is the layout the
 * character composes, drawn as whatever character the site has; any other is the owner's own,
 * which "Apply with composition" no longer touches, nor the options the owner set. Apply hands
 * back section styles, and counts only those. adminSite(), adminPost() and templateId() are
 * pages_admin_test.php's; designFields() design_test.php's; builderBand() and builderRequest()
 * builder_api_test.php's.
 */

testBothDrivers('a block that follows the character is drawn in its layout, and changes with it', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', 'about', 'About', true, [['type' => 'hero', 'content' => ['heading' => 'Hi'], 'layout' => '']]);
    assertEquals('', (string) ($db->one('SELECT layout FROM page_blocks')['layout'] ?? '-'), 'stored following');
    Composition::remember($db, 'soft');
    assertContains('layout-split', dispatch('/about')->body, 'Soft\'s hero');
    Composition::remember($db, 'minimal');
    assertContains('layout-center', dispatch('/about')->body, 'and Minimal\'s, with nothing applied');
});

testBothDrivers('Apply hands back section styles, and keeps the layouts and options the owner chose', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'stats', 'content' => ['items' => [['value' => '1', 'label' => 'one']]], 'style' => ['surface' => 'contrast'], 'layout' => 'four', 'options' => []],
        ['type' => 'hero', 'content' => ['heading' => 'Hi'], 'layout' => '', 'options' => ['height' => 'tall']],
    ]);
    assertEquals(1, Composition::styledByHand($db), 'one section styled by hand; a layout or an option is not a style');
    assertEquals(1, Composition::ownLayouts($db, blockRegistry()), 'one block with a layout of its own');

    $asked = adminPost('/admin/appearance', designFields(Presets::get('bold')) + ['character' => 'bold', 'action' => 'save']);
    assertContains('One section you styled by hand goes back to the character.', $asked->body, 'the sections Apply hands back');
    assertContains(e(t('inspector.apply.own_layout_one')), $asked->body, 'and, beside them, the block that keeps its layout');

    adminPost('/admin/appearance', designFields(Presets::get('bold')) + ['character' => 'bold', 'action' => 'save_composition']);
    assertEquals(0, Composition::styledByHand($db), 'the section style handed back');
    $rows = $db->all('SELECT block_type, layout, options_json FROM page_blocks ORDER BY id');
    assertEquals('four', (string) $rows[0]['layout'], 'the owner\'s layout, kept');
    assertEquals('', (string) $rows[1]['layout'], 'the one following, still following');
    assertEquals(['height' => 'tall'], json_decode((string) $rows[1]['options_json'], true), 'the owner\'s option, kept');
    $body = dispatch('/about')->body;
    assertContains('layout-four', $body, 'drawn as the owner chose');
    assertContains('block-hero layout-' . Composition::layout(blockRegistry(), 'bold', 'hero'), $body, 'and as Bold composes the rest');
});

testBothDrivers('the inspector shows the character\'s layout as the one followed, never as a change', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', true, [['type' => 'stats', 'content' => ['items' => [['value' => '1', 'label' => 'one']]], 'layout' => '']]);
    [, $section, [$block]] = builderBand($id);
    $inspect = static fn (array $b): string => json_decode(builderRequest("/admin/pages/{$id}/inspect", ['kind' => 'block', 'key' => $b['key'], 'section' => $section, 'blocks' => [$b], 'number' => 1])->body, true)['html'] ?? fail('no inspector');
    $composed = Composition::layout(blockRegistry(), Composition::active($db), 'stats');

    $following = $inspect(['layout' => ''] + $block);
    assertTrue(preg_match('~value="" data-tile="' . $composed . '"[^>]* checked~', $following) === 1, 'the character\'s tile, its value following');
    // Marked "Default" on its tile, and the readout the layout's name alone (CHANGED
    // DELIBERATELY, D-195: "· the character's" after the name was cut short in the inspector).
    assertTrue(preg_match('~data-tile="' . $composed . '"[^>]*>.*?<span class="tile-tag">' . preg_quote(e(t('builder.layout_default')), '~') . '</span></label>~s', $following) === 1, 'the character\'s tile says Default');
    assertEquals(1, substr_count($following, 'class="tile-tag"'), 'and no other tile');
    assertContains('class="readout" title="' . e(t('block.stats.layout.' . $composed)) . '">', $following, 'the readout, the layout\'s name alone');
    assertTrue(!str_contains($following, 'value="b.layout:"'), 'no way back from where it already is');

    $own = $inspect(['layout' => 'four'] + $block);
    assertTrue(preg_match('~value="four" data-tile="four"[^>]* checked~', $own) === 1, 'the owner\'s own');
    assertContains('value="b.layout:"', $own, 'and the way back to following');
});

testBothDrivers('a new block in the builder starts following, and the builder knows what that draws', function (string $driver) {
    $id = builderPage($driver);
    preg_match('~<script type="application/json" data-pb-data>(.*?)</script>~s', dispatch("/admin/pages/{$id}")->body, $m);
    $data = json_decode($m[1] ?? '', true);
    assertTrue(is_array($data), 'the builder\'s data');
    $stats = array_values(array_filter($data['library'] ?? [], static fn (array $item): bool => $item['type'] === 'stats'))[0] ?? [];
    assertEquals('', $stats['layout'] ?? null, 'a new block follows');
    assertEquals(Composition::layout(blockRegistry(), 'minimal', 'stats'), $data['composed']['stats'] ?? null, 'and the builder knows it draws in the character\'s');
});

testBothDrivers('migration 0038: a layout that is the site\'s character\'s becomes following, another stays', function (string $driver) {
    $db = adminSite($driver);
    Composition::remember($db, 'soft');
    createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'hero', 'content' => ['heading' => 'Hi'], 'layout' => 'split'],
        ['type' => 'image_text', 'content' => [], 'layout' => 'image-left'],
    ]);
    $sql = (string) file_get_contents(dirname(__DIR__) . '/migrations/0038_blocks_follow_character.sql');
    foreach (array_filter(array_map('trim', explode(';', (string) preg_replace('~^--.*$~m', '', $sql)))) as $statement) {
        $db->query($statement);
    }
    $rows = $db->all('SELECT block_type, layout FROM page_blocks ORDER BY id');
    assertEquals('', (string) $rows[0]['layout'], 'Soft\'s split hero, following');
    assertEquals('image-left', (string) $rows[1]['layout'], 'image-left, not Soft\'s image-right, kept');
});

testBothDrivers('the demo\'s page of every block keeps every layout and option by hand, and its other pages follow', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    App\Modules\Demo\DemoSite::seed($db, blockRegistry(), 'en');
    $blocks = static fn (string $key): array => $db->all('SELECT b.layout, b.options_json FROM page_blocks b JOIN pages p ON p.id = b.page_id WHERE p.slug = ?', [$key]);
    $showroom = $blocks('blocks');
    assertTrue($showroom !== [] && array_filter($showroom, static fn (array $b): bool => (string) $b['layout'] === '') === [], 'every block of the page of every block by hand');
    // Every block of the home page follows, not only its hero (D-194, the owner) — but this
    // four numbers, in a row of four, and this week's events, a list with no picture area
    // (CHANGED DELIBERATELY, D-213).
    $home = $blocks('');
    assertEquals(20, count($home), 'the home page\'s ten blocks, in English and its Croatian translation');
    assertEquals(['four', 'list', 'four', 'list'], array_values(array_map(static fn (array $b): string => (string) $b['layout'], array_filter($home, static fn (array $b): bool => (string) $b['layout'] !== ''))), 'every block of the home page following but the numbers and the events');
});
