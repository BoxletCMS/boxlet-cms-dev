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
        'version' => 1,
        'id' => 'harbour',
        'name' => ['en' => 'Harbour', 'hr' => 'Luka'],
        'description' => ['en' => 'Navy and sand.'],
        'decisions' => [
            'seed' => '#1d3557', 'secondary' => '#f1e3c6', 'typography' => 'classic', 'text_size' => 'normal', 'scale' => '1.25',
            'spacing' => 'roomy', 'radius' => 'subtle', 'shadow' => 'soft', 'container' => '60', 'surface_contrast' => 'medium',
            'header_width' => 'content', 'boxed' => 'no', 'page_background' => 'surface',
        ],
        'look' => [
            'header_arrangement' => 'left', 'header_behaviour' => 'sticky', 'footer_layout' => 'columns', 'footer_edge' => 'line',
            'small_print_row' => 'split', 'header_surface' => 'plain', 'footer_surface' => 'tinted', 'density' => 'normal',
            'header_edge' => 'line', 'logo_size' => 'medium', 'brand' => 'both', 'nav_style' => 'bar', 'nav_ink' => 'ink',
            'header_button' => 'outline', 'footer_columns' => '3', 'footer_links' => 'auto',
        ],
        'composition' => [
            'section' => ['surface' => 'plain', 'rhythm' => 'normal', 'width' => 'normal', 'align' => 'left', 'divider' => 'none'],
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
    assertEquals(array_keys(Presets::get(Presets::DEFAULT)), array_keys($set['decisions']), 'decision order');
    assertEquals('', $set['decisions']['color_text'], 'a neutral default');
    assertEquals('60', $set['decisions']['container'], 'a character decision');
    assertEquals(sampleSet()['look'], $set['look'], 'look');
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

    $raw['decisions']['radius'] = 3;
    assertContains('decisions.radius', implode(' ', parseSet($raw)['errors']), 'a number for a closed set');
});

test('what is refused, each with its field and reason', function () {
    $refused = static function (array $set, string $expected, string $what): void {
        $read = parseSet($set);
        assertEquals(null, $read['set'], "{$what} was read");
        assertContains($expected, implode(' | ', $read['errors']), $what);
    };
    $set = sampleSet();

    $refused(['format' => 'something-else'] + $set, 'not a Boxlet design', 'another format');
    $refused(['version' => 2] + $set, 'format version 2', 'a version from the future');
    $refused(['id' => 'Harbour Design'] + $set, 'id:', 'an id that is not a slug');
    $refused(['name' => []] + $set, 'name:', 'no name');
    $refused(array_replace_recursive($set, ['decisions' => ['typography' => 'comic']]), 'decisions.typography', 'an unknown typography');
    $refused(array_replace_recursive($set, ['decisions' => ['seed' => 'navy']]), 'decisions.seed', 'a colour that is not a hex');
    $refused(array_replace_recursive($set, ['decisions' => ['font_url' => 'https://example.com/f.woff2']]), 'decisions.font_url', 'a key that is no decision');
    $refused(array_replace_recursive($set, ['look' => ['nav_style' => 'neon']]), 'look.nav_style', 'a look value outside its set');
    $refused(array_replace_recursive($set, ['composition' => ['section' => ['surface' => 'image']]]), 'composition.section.surface', 'a picture surface');
    $refused(array_replace_recursive($set, ['composition' => ['surfaces' => ['hero' => 'neon']]]), 'composition.surfaces.hero', 'a surface outside its set');

    // A character says every header and footer choice.
    $partial = $set;
    unset($partial['look']['footer_links']);
    $refused($partial, 'look.footer_links', 'a character missing a choice');
});

test('a design that fails contrast is refused, naming the pair (D-154)', function () {
    $raw = array_replace_recursive(sampleSet(), ['decisions' => ['color_background' => '#ffffff', 'color_text' => '#f4f4f4']]);
    $read = parseSet($raw);
    assertEquals(null, $read['set'], 'an unreadable design was read');
    assertContains('WCAG AA needs at least', implode(' ', $read['errors']), 'the failing pair');
});

test('too large and too deep are refused before anything is read', function () {
    assertContains('larger than', implode(' ', DesignSet::parse(str_repeat(' ', DesignSet::MAX_BYTES + 1), blockRegistry())['errors']), 'too large');
    $deep = '{"format":"boxlet-design-set","version":1,"x":' . str_repeat('[', 10) . str_repeat(']', 10) . '}';
    assertContains('nested more than', implode(' ', DesignSet::parse($deep, blockRegistry())['errors']), 'too deep');
    assertContains('not JSON', implode(' ', DesignSet::parse('{"format":', blockRegistry())['errors']), 'broken JSON');
});

test('what cannot be carried over is left out with a warning, and the rest is read', function () {
    $raw = sampleSet();
    $raw['made_with'] = 'an AI';
    $raw['composition']['layouts']['hero'] = 'diagonal';
    $raw['composition']['surfaces']['carousel'] = 'tinted';
    $read = parseSet($raw);

    assertEquals([], $read['errors'], 'errors');
    $warnings = implode(' | ', $read['warnings']);
    assertContains('made_with', $warnings, 'an unknown top-level key');
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
    assertEquals(ChromeLook::OPTIONS, $vocabulary['look'], 'look');
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
