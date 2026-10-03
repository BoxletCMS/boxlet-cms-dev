<?php

use App\Modules\Design\DesignSet;
use App\Modules\Design\DesignVocabulary;
use App\Modules\Design\Presets;
use App\Modules\Design\Typography;
use App\Modules\Settings\ChromeLook;

// The `boxlet-design-set` format (PLAN.md D-152): what DesignSet::parse() refuses, what it
// only warns about, and that export() writes what parse() reads back.

/**
 * A complete character as a PHP array, which each test changes in one place.
 *
 * @return array<string, mixed>
 */
function sampleSet(): array
{
    return [
        'format' => 'boxlet-design-set',
        'version' => 2,
        'id' => 'harbour',
        'name' => ['en' => 'Harbour', 'hr' => 'Luka'],
        'description' => ['en' => 'Navy and sand.'],
        'decisions' => [
            'seed' => '#1d3557', 'secondary' => '#f1e3c6', 'typography' => 'classic', 'text_size' => '16', 'scale' => '1.25',
            'spacing' => '1.25', 'radius' => '4', 'shadow' => 'soft', 'container' => '60', 'surface_contrast' => '50',
            'header_width' => 'content', 'boxed' => 'no', 'page_background' => 'surface',
        ],
        'look' => [
            'header_arrangement' => 'left', 'header_behaviour' => 'sticky', 'footer_layout' => 'columns', 'footer_edge' => 'line',
            'small_print_row' => 'split', 'header_surface' => 'plain', 'footer_surface' => 'tinted', 'header_height' => '72',
            'header_edge' => 'line', 'logo_size' => '44', 'brand' => 'both', 'nav_style' => 'bar', 'nav_ink' => 'ink',
            'header_button' => 'outline', 'footer_columns' => '3', 'footer_links' => 'auto',
            'header_opacity' => '100', 'header_blur' => '0',
        ],
        'composition' => [
            'section' => ['surface' => 'plain', 'pad_top' => '', 'pad_bottom' => '96', 'min_height' => '0', 'width' => 'normal', 'align' => 'left', 'divider' => 'none', 'animation' => 'fade'],
            'surfaces' => ['image_text' => 'tinted'],
            'dividers' => ['text' => 'line'],
            'layouts' => ['hero' => 'split', 'image_text' => 'image-right', 'text' => 'single'],
        ],
    ];
}

/**
 * @param array<string, mixed> $set
 * @return array{set: array<string, mixed>|null, errors: list<string>, warnings: list<string>}
 */
function parseSet(array $set): array
{
    return DesignSet::parse((string) json_encode($set), blockRegistry());
}

test('a complete set is read: decisions validated and in stored order, look and composition kept', function () {
    $read = parseSet(sampleSet());
    assertEquals([], $read['errors'], 'errors');
    assertEquals([], $read['warnings'], 'warnings');
    $set = $read['set'] ?? fail('nothing was read');

    assertEquals('harbour', $set['id'], 'id');
    assertEquals(['en' => 'Harbour', 'hr' => 'Luka'], $set['name'], 'name');
    // Every decision, the neutral ones filled in, in the order design_tokens stores them.
    assertEquals(App\Modules\Design\Vocabulary\Decisions::keys('decisions'), array_keys($set['decisions']), 'decision order');
    assertEquals('', $set['decisions']['color_text'], 'a neutral default');
    assertEquals('60', $set['decisions']['container'], 'a character decision');
    // In the vocabulary's order, which is not the file's.
    $look = $set['look'];
    $expected = sampleSet()['look'];
    ksort($look);
    ksort($expected);
    assertEquals($expected, $look, 'look');
    assertEquals(['hero' => 'split', 'image_text' => 'image-right', 'text' => 'single'], $set['composition']['layouts'], 'layouts');
});

test('export writes what parse reads back, without the neutral defaults', function () {
    $set = parseSet(sampleSet())['set'] ?? fail('nothing was read');
    // An owner's exceptions travel (D-153): a colour by hand and a nudge.
    $set['decisions']['color_link'] = '#0b3d91';
    $set['decisions']['nudge_h1'] = '4';

    $json = DesignSet::export($set['id'], $set['name'], $set['description'], $set['decisions'], $set['look'], $set['composition']);
    $file = json_decode($json, true);
    assertTrue(!array_key_exists('color_text', $file['decisions']), 'a neutral default was written');
    assertEquals('#0b3d91', $file['decisions']['color_link'] ?? null, 'a colour by hand');
    assertEquals(['format', 'version', 'id', 'name', 'description', 'decisions', 'look', 'composition'], array_keys($file), 'key order');

    $again = DesignSet::parse($json, blockRegistry());
    assertEquals([], $again['errors'], 'errors reading the export');
    assertEquals($set, $again['set'], 'the set after a round trip');
});

test('a set with no composition is a design; its look may follow the character', function () {
    $raw = sampleSet();
    unset($raw['composition']);
    $raw['look'] = ['nav_style' => 'pills'];
    $read = parseSet($raw);
    assertEquals([], $read['errors'], 'errors');
    // array_key_exists, not ??: a composition that is null is the answer, not a missing key.
    assertTrue(array_key_exists('composition', $read['set'] ?? []), 'the set has no composition key');
    assertEquals(null, ($read['set'] ?? [])['composition'], 'composition');
    assertEquals('', $read['set']['look']['header_arrangement'] ?? null, 'a choice left to the character');
    assertEquals('pills', $read['set']['look']['nav_style'] ?? null, 'a choice made');
});

test('numbers are taken for the numeric decisions, and only for them', function () {
    $raw = sampleSet();
    $raw['decisions']['container'] = 60;
    $raw['decisions']['scale'] = 1.25;
    assertEquals('60', parseSet($raw)['set']['decisions']['container'] ?? null, 'a number of rem');

    $raw['decisions']['shadow'] = 3;
    assertContains('decisions.shadow', implode(' ', parseSet($raw)['errors']), 'a number for a closed set');
});

test('what is refused, each with its field and reason', function () {
    $refused = static function (array $set, string $expected, string $what): void {
        $read = parseSet($set);
        assertEquals(null, $read['set'], "{$what} was read");
        assertContains($expected, implode(' | ', $read['errors']), $what);
    };
    $set = sampleSet();

    $refused(['format' => 'something-else'] + $set, 'not a Boxlet design', 'another format');
    $refused(['version' => 3] + $set, 'format version 3', 'a version from the future');
    $refused(['version' => 1] + $set, 'format version 1', 'and one from the past (D-162)');
    $refused(['id' => 'Harbour Design'] + $set, 'id:', 'an id that is not a slug');
    $refused(['name' => []] + $set, 'name:', 'no name');
    $refused(array_replace_recursive($set, ['decisions' => ['typography' => 'comic']]), 'decisions.typography', 'an unknown typography');
    $refused(array_replace_recursive($set, ['decisions' => ['seed' => 'navy']]), 'decisions.seed', 'a colour that is not a hex');
    $refused(array_replace_recursive($set, ['look' => ['nav_style' => 'neon']]), 'look.nav_style', 'a look value outside its set');
    $refused(array_replace_recursive($set, ['composition' => ['section' => ['surface' => 'image']]]), 'composition.section.surface', 'a picture surface');
    $refused(array_replace_recursive($set, ['composition' => ['surfaces' => ['hero' => 'neon']]]), 'composition.surfaces.hero', 'a surface outside its set');

});

// THE RULE CHANGED DELIBERATELY with D-164: a character missing a header or footer choice was
// refused, because nothing said what it meant. Every choice has a neutral answer now, as every
// decision does, and a choice left out is that answer — the same rule as a decision left out.
test('a character that leaves a header or footer choice out takes its neutral answer', function () {
    $partial = sampleSet();
    unset($partial['look']['footer_links']);
    $read = parseSet($partial);
    assertEquals([], $read['errors'], 'refused');
    assertEquals('', $read['set']['look']['footer_links'] ?? null, 'the set follows');
    withCustomDesigns(['harbour.json' => (string) json_encode($partial)], function () {
        assertEquals(App\Modules\Design\Vocabulary\Decisions::neutral()['footer_links'], App\Modules\Design\Characters::look('harbour')['footer_links'], 'the character answers with the neutral');
    });
});

// D-165: a composition's section is the composed keys of SectionStyle — numbers on their
// steps, the padding '' for the design's section gap, and the old rhythm a key it does not know.
test('a composition says how a section is spaced, with numbers, and nothing it cannot compose', function () {
    $read = parseSet(sampleSet());
    $section = $read['set']['composition']['section'] ?? [];
    assertEquals(App\Modules\Design\SectionStyle::composed(), array_keys($section), 'every composed key, in order');
    assertEquals('', $section['pad_top'], 'the section gap');
    assertEquals('96', $section['pad_bottom'], 'a padding of its own');
    assertEquals('top', $section['v_align'], 'a key left out takes its default');
    assertEquals('fade', $section['animation'], 'an animation');

    $old = array_replace_recursive(sampleSet(), ['composition' => ['section' => ['rhythm' => 'airy']]]);
    assertContains('composition.section.rhythm', implode(' ', parseSet($old)['warnings']), 'the old rhythm is a key it does not know');
    $tall = array_replace_recursive(sampleSet(), ['composition' => ['section' => ['min_height' => '150']]]);
    assertContains('composition.section.min_height', implode(' ', parseSet($tall)['errors']), 'a height past its bounds');
    $empty = array_replace_recursive(sampleSet(), ['composition' => ['section' => ['min_height' => '']]]);
    assertContains('composition.section.min_height', implode(' ', parseSet($empty)['errors']), 'a height of nothing: only the padding may be the gap');
    $hidden = array_replace_recursive(sampleSet(), ['composition' => ['section' => ['hide_mobile' => 'yes', 'anchor' => 'top']]]);
    assertEquals(2, count(parseSet($hidden)['warnings']), 'the visibility and the anchor are no character\'s to set');
});

test('a design that fails contrast is refused, naming the pair (D-154)', function () {
    $raw = array_replace_recursive(sampleSet(), ['decisions' => ['color_background' => '#ffffff', 'color_text' => '#f4f4f4']]);
    $read = parseSet($raw);
    assertEquals(null, $read['set'], 'an unreadable design was read');
    assertContains('WCAG AA needs at least', implode(' ', $read['errors']), 'the failing pair');
});

test('too large and too deep are refused before anything is read', function () {
    assertContains('larger than', implode(' ', DesignSet::parse(str_repeat(' ', DesignSet::MAX_BYTES + 1), blockRegistry())['errors']), 'too large');
    // Twelve levels since D-169, for a pattern's words in a card; past that, refused.
    $deep = '{"format":"boxlet-design-set","version":2,"x":' . str_repeat('[', 14) . str_repeat(']', 14) . '}';
    assertContains('nested more than', implode(' ', DesignSet::parse($deep, blockRegistry())['errors']), 'too deep');
    assertContains('not JSON', implode(' ', DesignSet::parse('{"format":', blockRegistry())['errors']), 'broken JSON');
});

test('what cannot be carried over is left out with a warning, and the rest is read', function () {
    $raw = sampleSet();
    $raw['made_with'] = 'an AI';
    // A decision or a look choice Boxlet does not know is a later v2's optional key, left out
    // (D-183). It was refused as "a key that is no decision": the rule changed on purpose
    // with the format's freeze.
    $raw['decisions']['font_url'] = 'https://example.com/f.woff2';
    $raw['look']['header_glow'] = 'strong';
    $raw['composition']['layouts']['hero'] = 'diagonal';
    $raw['composition']['surfaces']['carousel'] = 'tinted';
    $read = parseSet($raw);

    assertEquals([], $read['errors'], 'errors');
    $warnings = implode(' | ', $read['warnings']);
    assertContains('made_with', $warnings, 'an unknown top-level key');
    assertContains('decisions.font_url', $warnings, 'an unknown decision');
    assertContains('look.header_glow', $warnings, 'an unknown look choice');
    assertTrue(!isset($read['set']['decisions']['font_url']), 'the unknown decision was kept');
    assertContains('composition.layouts.hero', $warnings, 'a layout the block does not offer');
    assertContains('composition.surfaces.carousel', $warnings, 'a block this site does not have');
    assertTrue(!isset($read['set']['composition']['layouts']['hero']), 'the layout was kept');
});

test('names and descriptions are one clean line of bounded length', function () {
    $raw = sampleSet();
    $raw['name'] = ['en' => "  Har\x07bour\n\tnavy  ", 'not a locale' => 'x'];
    $raw['description'] = ['en' => str_repeat('a', 400)];
    $set = parseSet($raw)['set'] ?? fail('nothing was read');
    assertEquals(['en' => 'Har bour navy'], $set['name'], 'name');
    assertEquals(300, mb_strlen($set['description']['en']), 'description length');
});

test('the vocabulary is read off the code that validates', function () {
    $vocabulary = DesignVocabulary::vocabulary(blockRegistry());
    assertEquals(array_keys(Typography::PAIRINGS), $vocabulary['decisions']['typography']['values'], 'typography');
    assertEquals(ChromeLook::keys(), array_keys($vocabulary['look']), 'look');
    assertEquals(choicesOf('header_arrangement'), $vocabulary['look']['header_arrangement']['values'], 'a look choice\'s values');
    assertEquals(blockRegistry()->get('hero')['layouts'], $vocabulary['composition']['layouts']['hero'], 'a block\'s layouts');
    assertTrue(!in_array('image', $vocabulary['composition']['surfaces'], true), 'a picture surface is offered');
});

// The copy in the repository, for developers and AI tools, is the one the code writes.
test('designs/design-set.schema.json is the schema the code generates', function () {
    $file = dirname(__DIR__) . '/designs/design-set.schema.json';
    assertEquals(DesignVocabulary::schemaJson(blockRegistry()), (string) @file_get_contents($file), 'designs/design-set.schema.json');
});

test('the schema is served to the admin, at an address without an extension', function () {
    adminSite('sqlite');
    $response = dispatch('/admin/appearance/schema');
    assertEquals(200, $response->status, 'status');
    assertContains('application/schema+json', $response->headers['Content-Type'] ?? '', 'type');
    assertContains('filename="design-set.schema.json"', $response->headers['Content-Disposition'] ?? '', 'the file name');
    assertEquals(DesignVocabulary::schemaJson(blockRegistry()), $response->body, 'the generated schema');

    $_SESSION = [];
    assertRedirectedTo('/admin/login', dispatch('/admin/appearance/schema'));
});

// The owner's review of step 2: a short hex is taken, and kept only in its long form.
test('a colour given as #rgb is read, and stored and exported as #rrggbb', function () {
    $raw = sampleSet();
    $raw['decisions']['seed'] = '#1D3';
    $raw['decisions']['color_link'] = '#036';
    $set = parseSet($raw)['set'] ?? fail('nothing was read');
    assertEquals('#11dd33', $set['decisions']['seed'], 'the seed as stored');
    assertEquals('#003366', $set['decisions']['color_link'], 'a colour by hand as stored');

    $file = json_decode(DesignSet::export($set['id'], $set['name'], $set['description'], $set['decisions'], $set['look'], $set['composition']), true);
    assertEquals('#11dd33', $file['decisions']['seed'] ?? null, 'the seed as exported');
    assertEquals('#003366', $file['decisions']['color_link'] ?? null, 'a colour by hand as exported');
});

// The handoff's test 2: parse(export(x)) == x, for every character Boxlet ships.
test('every core character survives export and parse unchanged', function () {
    foreach (App\Modules\Design\Characters::CORE as $id) {
        $name = ['en' => App\Modules\Design\Characters::label($id)];
        $description = ['en' => App\Modules\Design\Characters::hint($id)];
        // The decisions half: Presets::get answers the look's keys too since D-164.
        $decisions = array_intersect_key(Presets::get($id), array_flip(App\Modules\Design\Vocabulary\Decisions::keys('decisions')));
        $look = App\Modules\Design\Characters::look($id);
        $composition = App\Modules\Design\Characters::composition($id);

        $read = DesignSet::parse(DesignSet::export($id, $name, $description, $decisions, $look, $composition, 'Boxlet'), blockRegistry());
        assertEquals([], $read['errors'], "{$id}: errors");
        assertEquals([], $read['warnings'], "{$id}: warnings");
        $set = $read['set'] ?? fail("{$id}: nothing read");
        assertEquals([$id, $name, $description, $decisions, $look, $composition], [$set['id'], $set['name'], $set['description'], $set['decisions'], $set['look'], $set['composition']], "{$id} after the round trip");
    }
});

// D-169: a set's starter sections, the one place a set carries words — per language, with the
// English for a language the set has none in.
test('a set\'s patterns are read with their words per language, and only what a pattern may carry', function () {
    $raw = sampleSet();
    $raw['patterns'] = [
        ['id' => 'welcome', 'name' => ['en' => 'Welcome', 'hr' => 'Dobrodošlica'],
            'section' => ['layout' => 'halves', 'style' => ['surface' => 'tinted', 'pad_top' => '112', 'image' => 4]],
            'blocks' => [
                ['type' => 'hero', 'layout' => 'center', 'options' => ['height' => 'tall'], 'content' => [
                    'heading' => ['en' => 'Hello', 'hr' => 'Bok'],
                    'image' => 7,
                    'cta' => ['label' => 'More', 'url' => 'javascript:alert(1)'],
                ]],
                ['type' => 'text', 'column' => 1, 'content' => ['body' => ['en' => '<p>Words <script>x</script></p>']]],
            ]],
        ['id' => 'elsewhere', 'name' => ['en' => 'A block this site has not got'], 'blocks' => [['type' => 'carousel', 'content' => []]]],
    ];
    $read = parseSet($raw);
    assertEquals([], $read['errors'], 'errors');
    $patterns = $read['set']['patterns'] ?? [];
    assertEquals(['welcome'], array_column($patterns, 'id'), 'the pattern of a block this site lacks is left out');
    $welcome = $patterns[0];
    assertEquals('halves', $welcome['section']['layout'], 'its layout');
    assertEquals(null, $welcome['section']['style']['image'], 'a picture it cannot have');
    assertEquals('112', $welcome['section']['style']['pad_top'], 'its spacing');
    assertEquals(['height' => 'tall'], $welcome['blocks'][0]['options'], 'its options');
    assertTrue(!isset($welcome['blocks'][0]['content']['image']), 'a picture in a pattern');
    assertTrue(!isset($welcome['blocks'][0]['content']['cta']), 'a link no link field accepts');
    assertEquals(['en' => '<p>Words </p>'], $welcome['blocks'][1]['content']['body'], 'rich text through the sanitiser');
    assertEquals(1, $welcome['blocks'][1]['column'], 'the column it stands in');
    $warnings = implode(' | ', $read['warnings']);
    assertContains('patterns.0.blocks.0.content.image', $warnings, 'the picture, named');
    assertContains('patterns.1.blocks.0', $warnings, 'the unknown block, named');

    assertEquals('Bok', App\Modules\Design\DesignSetPatterns::in($welcome['blocks'][0]['content'], 'hr')['heading'], 'in Croatian');
    assertEquals('Hello', App\Modules\Design\DesignSetPatterns::in($welcome['blocks'][0]['content'], 'de')['heading'], 'in English where the set has no German');

    // And they travel: what export writes, parse reads back the same.
    $set = $read['set'] ?? fail('nothing was read');
    $again = DesignSet::parse(DesignSet::export($set['id'], $set['name'], $set['description'], $set['decisions'], $set['look'], $set['composition'], '', $set['patterns']), blockRegistry());
    assertEquals($set['patterns'], $again['set']['patterns'] ?? null, 'patterns after a round trip');
});

test('a pattern without an id of its own or without a block is refused', function () {
    $raw = sampleSet();
    $raw['patterns'] = [['id' => 'Not A Slug', 'name' => ['en' => 'x'], 'blocks' => [['type' => 'text', 'content' => []]]]];
    assertContains('patterns.0.id', implode(' ', parseSet($raw)['errors']), 'a bad id');
    $raw['patterns'] = [['id' => 'empty', 'name' => ['en' => 'x'], 'blocks' => []]];
    assertContains('patterns.0.blocks', implode(' ', parseSet($raw)['errors']), 'no blocks');
});

test('every core character offers the mockup\'s four starter sections, in Croatian and English', function () {
    foreach (App\Modules\Design\Characters::CORE as $id) {
        $patterns = App\Modules\Design\Characters::patterns($id);
        assertEquals(['hero-button', 'three-cards', 'text-quote', 'call-to-action'], array_column($patterns, 'id'), "{$id}'s patterns");
        assertEquals('Spremni za početak?', App\Modules\Design\DesignSetPatterns::in($patterns[3]['blocks'][0]['content'], 'hr')['heading'], "{$id} in Croatian");
    }
});
