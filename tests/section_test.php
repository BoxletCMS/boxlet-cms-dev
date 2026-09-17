<?php

use App\Modules\Design\Palette;
use App\Modules\Design\SectionStyle;
use App\Modules\Design\Tokens;
use App\Modules\Design\Typography;

// Layer 2: section styles, validated against their closed sets and rendered as classes.

test('section style values outside the closed sets fall back to the defaults', function () {
    $normalized = SectionStyle::normalize(['surface' => 'neon', 'rhythm' => 'airy', 'width' => ['wide'], 'colour' => 'red']);

    assertEquals(array_merge(SectionStyle::DEFAULTS, ['rhythm' => 'airy']), $normalized, 'normalized');
    assertEquals(SectionStyle::DEFAULTS, SectionStyle::normalize('not an array'), 'wrong shape');
});

test('every section style key becomes one class on the wrapper', function () {
    assertEquals(['surface-plain', 'rhythm-normal', 'width-normal', 'align-left', 'divider-none'], SectionStyle::classes(SectionStyle::DEFAULTS), 'classes');
});

testBothDrivers("a section's surface and rhythm are saved and change only that section", function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'text', 'content' => ['body' => '<p>One</p>']],
        ['type' => 'text', 'content' => ['body' => '<p>Two</p>']],
    ]);
    [$one, $two] = array_map('strval', array_column($db->all('SELECT id FROM page_blocks ORDER BY sort'), 'id'));

    $response = adminPost("/admin/pages/{$id}", [
        'title' => 'About',
        'slug' => 'about',
        'blocks' => [
            ['id' => $one, 'type' => 'text', 'body' => '<p>One</p>', 'style' => SectionStyle::DEFAULTS],
            ['id' => $two, 'type' => 'text', 'body' => '<p>Two</p>', 'style' => ['surface' => 'contrast', 'rhythm' => 'airy', 'width' => 'enormous', 'divider' => 'curve']],
        ],
        'action' => 'save',
        '_end' => '1',
    ]);
    assertRedirectedTo("/admin/pages/{$id}", $response);

    $stored = json_decode((string) ($db->one('SELECT style_json FROM page_blocks WHERE id = ?', [(int) $two])['style_json'] ?? ''), true);
    // Derived from DEFAULTS rather than written out, so a change to the shape of a section
    // style touches the constant and not this literal. The assertions are unchanged: an
    // unknown width still falls back, and only the edited section moves. The sixth key
    // arrives with it because D-024 added one (a media id, null when no picture is set).
    $expected = array_merge(SectionStyle::DEFAULTS, ['surface' => 'contrast', 'rhythm' => 'airy', 'divider' => 'curve']);
    assertEquals($expected, $stored, 'stored style, unknown width replaced');

    $body = dispatch('/about')->body;
    assertContains('<section class="block block-text layout-single surface-plain rhythm-normal width-normal align-left divider-none">', $body, 'first section');
    assertContains('<section class="block block-text layout-single surface-contrast rhythm-airy width-normal align-left divider-curve">', $body, 'second section');
});

test('every section style, design choice, colour and pair has an admin label', function () {
    $keys = [];
    // Every key a section style HAS, taken from DEFAULTS rather than OPTIONS. OPTIONS is
    // only the enumerated five, so a list built from it silently skipped `image` — the
    // editor rendered the literal string "style.image" as a field label and this test
    // stayed green, which is the failure it exists to catch.
    foreach (array_keys(SectionStyle::DEFAULTS) as $key) {
        $keys[] = "style.{$key}";
    }
    // Per-value labels only where there are values to name: a media reference has none.
    foreach (SectionStyle::OPTIONS as $key => $values) {
        foreach ($values as $value) {
            $keys[] = "style.{$key}.{$value}";
        }
    }
    foreach (Tokens::choices() as $key => $values) {
        if ($key !== 'typography') {
            foreach ($values as $value) {
                $keys[] = "design.{$key}.{$value}";
            }
        }
    }
    foreach (array_keys(Typography::PAIRINGS) as $pairing) {
        $keys[] = "design.typography.{$pairing}";
    }
    foreach (array_keys(Palette::colors('#2f4f6f', '#ffe600', 'low')) as $color) {
        $keys[] = "design.color.{$color}";
    }
    $source = (string) file_get_contents(dirname(__DIR__) . '/app/Modules/Design/Palette.php');
    preg_match_all("~\['([a-z_]+)', '[a-z_]+', '[a-z-]+', '[a-z-]+'\]~", $source, $pairs);
    foreach ($pairs[1] as $pair) {
        $keys[] = "design.pair.{$pair}";
    }

    foreach ($keys as $key) {
        assertTrue(t($key) !== $key, "missing label {$key}");
    }
});
