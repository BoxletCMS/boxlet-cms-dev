<?php

use App\Modules\Design\Palette;
use App\Modules\Design\SectionStyle;
use App\Modules\Design\Tokens;
use App\Modules\Design\Typography;

// Layer 2: section styles, validated against their closed sets and rendered as classes.

// THE RULE CHANGED DELIBERATELY with D-165: a value outside its set used to fall back to the
// default; now it falls back to '' — "as the character composes it" — because that is what a
// key the owner has not set means. `rhythm` is gone, replaced by the padding numbers.
test('section style values outside their sets become "as the character has it"', function () {
    $normalized = SectionStyle::normalize(['surface' => 'neon', 'rhythm' => 'airy', 'width' => ['wide'], 'colour' => 'red', 'pad_top' => '120', 'min_height' => '150']);

    assertEquals('', $normalized['surface'], 'an unknown surface');
    assertEquals('', $normalized['width'], 'a width that is not a string');
    assertEquals('120', $normalized['pad_top'], 'a padding on its step');
    assertEquals('', $normalized['min_height'], 'a height past its bounds');
    assertTrue(!array_key_exists('rhythm', $normalized) && !array_key_exists('colour', $normalized), 'keys that are no section style');
    assertEquals(SectionStyle::normalize([]), SectionStyle::normalize('not an array'), 'wrong shape');
    assertTrue(!SectionStyle::overridden(SectionStyle::normalize([])), 'nothing set');
});

test('a padding off its step lands on it, and the stepped keys are numbers', function () {
    assertEquals('120', SectionStyle::clean('pad_top', '119'), 'onto the nearest step of 4');
    assertEquals('0', SectionStyle::clean('pad_bottom', 0), 'zero is a value, not nothing');
    assertEquals('50', SectionStyle::clean('min_height', '52'), 'the height on its step of 5');
    assertEquals('', SectionStyle::clean('pad_top', '204'), 'past the bounds');
    assertEquals('', SectionStyle::clean('pad_top', 'lots'), 'not a number');
});

test('an anchor is a slug, and never one of the ids the page already uses', function () {
    assertEquals('nase-usluge', SectionStyle::anchor(' #Naše usluge '), 'typed as a person types it');
    assertEquals('', SectionStyle::anchor('site-nav'), 'the menu\'s own id');
    assertEquals('', SectionStyle::anchor('form-3'), 'a form\'s own id');
    assertEquals('', SectionStyle::anchor('2024'), 'an id starts with a letter');
    assertEquals(64, strlen(SectionStyle::anchor(str_repeat('a', 80))), 'cut to its length');
});

test('each value becomes a class on the wrapper, the anchor and the animation attributes', function () {
    $effective = SectionStyle::effective(SectionStyle::normalize(['pad_top' => '120', 'min_height' => '50', 'v_align' => 'center', 'hide_mobile' => 'yes', 'anchor' => 'usluge', 'animation' => 'fade']), SectionStyle::DEFAULTS);
    assertEquals(['surface-plain', 'width-normal', 'align-left', 'divider-none', 'pad-t-120', 'min-h-50', 'v-center', 'hide-mobile'], SectionStyle::classes($effective), 'classes');
    assertEquals(' id="usluge" data-anim="fade"', SectionStyle::attributes($effective), 'attributes');
    // Nothing set is the section gap and no height: no class for either.
    assertEquals(['surface-plain', 'width-normal', 'align-left', 'divider-none'], SectionStyle::classes(SectionStyle::effective(SectionStyle::normalize([]), SectionStyle::DEFAULTS)), 'the defaults');
    assertEquals('', SectionStyle::attributes(SectionStyle::DEFAULTS), 'no anchor, no animation');
});

testBothDrivers("a section stores only what its owner set, and the rest is its character's", function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'text', 'content' => ['body' => '<p>One</p>']],
        ['type' => 'text', 'content' => ['body' => '<p>Two</p>']],
    ]);
    [$one, $two] = array_map('strval', blockIdsInOrder($db));

    $response = adminPost("/admin/pages/{$id}", [
        'title' => 'About',
        'slug' => 'about',
        'blocks' => [
            ['id' => $one, 'type' => 'text', 'body' => '<p>One</p>', 'style' => []],
            ['id' => $two, 'type' => 'text', 'body' => '<p>Two</p>', 'style' => ['surface' => 'contrast', 'pad_top' => '120', 'width' => 'enormous', 'divider' => 'curve']],
        ],
        'action' => 'publish',
        '_end' => '1',
    ]);
    assertRedirectedTo("/admin/pages/{$id}", $response);

    // The style is the SECTION's since D-095; sectionStyleOf() is the one place that knows.
    $stored = sectionStyleOf($db, (int) $two);
    $expected = array_merge(SectionStyle::normalize([]), ['surface' => 'contrast', 'pad_top' => '120', 'divider' => 'curve']);
    assertEquals($expected, $stored, 'stored style, an unknown width left to the character');
    assertEquals(SectionStyle::normalize([]), sectionStyleOf($db, (int) $one), 'the untouched section stores nothing');

    // Drawn: the untouched section as the character composes a text section; the other with
    // the owner's three values over it.
    $composed = App\Modules\Design\Composition::style(App\Modules\Design\Composition::active($db), 'text');
    $classes = static fn (array $style): string => implode(' ', SectionStyle::classes(SectionStyle::effective(SectionStyle::normalize($style), $composed)));
    $body = dispatch('/about')->body;
    assertContains('<section class="block block-text layout-single ' . $classes([]) . '">', $body, 'first section');
    assertContains('<section class="block block-text layout-single ' . $classes(['surface' => 'contrast', 'pad_top' => '120', 'divider' => 'curve']) . '">', $body, 'second section');
    assertContains('pad-t-120', $classes(['pad_top' => '120']), 'and the padding is a class');
});

test('every section style, design choice, colour and pair has an admin label', function () {
    $keys = [];
    // Every key a section style HAS, taken from DEFAULTS rather than OPTIONS. OPTIONS is
    // only the enumerated five, so a list built from it silently skipped `image` — the
    // editor rendered the literal string "style.image" as a field label and this test
    // stayed green, which is the failure it exists to catch.
    // From normalize([]) since D-165: DEFAULTS is only what a character composes now, and
    // the picture, the name, the anchor and the visibility are keys a section has besides.
    foreach (array_keys(SectionStyle::normalize([])) as $key) {
        $keys[] = "style.{$key}";
    }
    // Per-value labels only where there are values to name: a media reference has none.
    foreach (SectionStyle::OPTIONS as $key => $values) {
        foreach ($values as $value) {
            $keys[] = "style.{$key}.{$value}";
        }
    }
    // The header and footer's are labelled under chrome.look (chrome_look_test).
    foreach (array_diff_key(allChoices(), lookChoices()) as $key => $values) {
        // A family is named by its own name (Fonts), not by a label (D-185).
        if (!in_array($key, ['heading_font', 'body_font'], true)) {
            foreach ($values as $value) {
                $keys[] = "design.{$key}.{$value}";
            }
        }
    }
    foreach (lookChoices() as $key => $values) {
        foreach ($values as $value) {
            $keys[] = "chrome.look.{$key}.{$value}";
        }
    }
    foreach (array_keys(Typography::PAIRINGS) as $pairing) {
        $keys[] = "design.typography.{$pairing}";
    }
    foreach (array_keys(Palette::colors('#2f4f6f', '#ffe600', 20.0)) as $color) {
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
