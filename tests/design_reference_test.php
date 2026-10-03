<?php

use App\Modules\Design\DesignReference;
use App\Modules\Design\DesignVocabulary;
use App\Modules\Design\Presets;
use App\Modules\Design\SectionStyle;
use App\Modules\Design\SetCheck;
use App\Modules\Design\SetMeasure;
use App\Modules\Design\Tokens;
use App\Modules\Design\Vocabulary\Decisions;
use App\Modules\Design\Vocabulary\Descriptions;

/*
 * THE DESIGN SET FORMAT, FROZEN (PLAN.md D-183): every key says what it does, the schema and the
 * reference are written from the code that validates, and the checker a set's author runs says
 * what an import would and more. sampleSet() and parseSet() are design_set_test.php's.
 */

test('every key a set may hold has its one sentence, and nothing describes a key that is not there', function () {
    assertEquals(array_keys(Decisions::ALL), array_keys(Descriptions::KEYS), 'decisions and look');
    assertEquals(array_merge(array_keys(SectionStyle::OPTIONS), array_keys(SectionStyle::NUMBERS)), array_keys(Descriptions::SECTION), 'a section\'s style');
    foreach (Descriptions::KEYS + Descriptions::SECTION as $key => $sentence) {
        assertTrue(preg_match('~^[A-Z].*\.$~', $sentence) === 1 && substr_count($sentence, '. ') === 0, "{$key}: one sentence");
    }
});

test('the schema says of each key what it does and what it is when left out', function () {
    $schema = DesignVocabulary::schema(blockRegistry());
    foreach (['decisions', 'look'] as $part) {
        foreach ($schema['properties'][$part]['properties'] as $key => $property) {
            assertContains(Descriptions::KEYS[$key], $property['description'] ?? '', "{$part}.{$key}");
            if ($key !== 'seed') {
                assertTrue(array_key_exists('default', $property), "{$part}.{$key} has no default");
                assertEquals(Decisions::ALL[$key]['neutral'], (string) $property['default'], "{$part}.{$key}'s default");
            }
        }
    }
});

test('the reference names every key, block, layout and contrast pair', function () {
    $reference = DesignReference::markdown(blockRegistry());
    foreach (array_keys(Decisions::ALL) as $key) {
        assertContains('| `' . $key . '` |', $reference, $key);
    }
    foreach (blockRegistry()->types() as $type) {
        assertContains('| `' . $type . '` | ' . implode(', ', blockRegistry()->get($type)['layouts']), $reference, $type);
    }
    foreach (DesignReference::pairNames() as $pair) {
        assertContains('(`' . $pair . '`)', $reference, $pair);
    }
    assertContains('only by adding optional keys', $reference, 'the freeze');
    // The owner's copy in docs/ (private, so not in CI's checkout) is the one the code writes.
    $file = dirname(__DIR__) . '/docs/design-set-reference.md';
    if (is_file($file)) {
        assertEquals($reference, (string) file_get_contents($file), 'docs/design-set-reference.md');
    }
});

test('the checker passes the five characters, with what only it says', function () {
    foreach (glob(dirname(__DIR__) . '/designs/core/*.json') ?: [] as $file) {
        $report = SetCheck::check((string) file_get_contents($file), blockRegistry());
        assertEquals([], $report['errors'], basename($file));
        assertTrue($report['character'], basename($file) . ' is a character');
        assertContains('veil over a picture', implode(' | ', $report['notes']), basename($file));
    }
});

test('the checker refuses what an import refuses, and warns of the other mode, unknown keys, steps and narrow words', function () {
    $refused = sampleSet();
    $refused['decisions']['seed'] = '#ffff00';
    $report = SetCheck::check((string) json_encode($refused), blockRegistry());
    assertContains('decisions.seed', implode(' | ', $report['errors']), 'a main colour too light to read');

    $set = sampleSet();
    $set['decisions']['wobble'] = 'yes';
    // Dark text by hand reads on the light page and not on a dark one: what a derivation
    // cannot mend (the main colour is lifted in dark mode since D-184).
    $set['decisions']['color_text'] = '#222222';
    $set['decisions']['container'] = '36';
    $set['decisions']['section_gap'] = '50';
    $set['patterns'] = [[
        'id' => 'side', 'name' => ['en' => 'Side by side'],
        'section' => ['layout' => 'thirds', 'style' => ['width' => 'normal']],
        'blocks' => [['type' => 'text', 'column' => 2, 'content' => ['body' => ['en' => '<p>Words.</p>']]]],
    ]];
    $report = SetCheck::check((string) json_encode($set), blockRegistry());
    $warnings = implode(' | ', $report['warnings']);
    assertEquals([], $report['errors'], 'errors');
    assertContains('decisions.wobble', $warnings, 'an unknown key, left out');
    assertContains('in dark mode', $warnings, 'the other mode');
    assertContains('decisions.section_gap: 50 is not on its step', $warnings, 'a number put on its step');
    // A width of its own is not widened: its columns stack (D-184), and the author hears it.
    assertContains('pattern side: text, single, in column 3 of thirds: its columns stack', $warnings, 'a pattern\'s columns stacked');
});

// Measured in the browser under Editorial (content 42rem, spacing 1.25), D-183: the grids'
// arithmetic is held to it.
test('the width of words by the grids alone is worked out as the browser measured it', function () {
    $editorial = Tokens::resolve(Presets::get('editorial'));
    $at = static fn (string $width, string $columns, int $column, string $type, string $layout): string => sprintf('%.1f', SetMeasure::words($editorial, $width, $columns, $column, $type, $layout));
    assertEquals('13.2', $at('normal', 'wide-left', 1, 'quote', 'plain'), 'the narrow column of wide-left');
    assertEquals('26.3', $at('normal', 'wide-left', 0, 'text', 'single'), 'the wide column of wide-left');
    assertEquals('14.8', $at('normal', 'one', 0, 'form', 'beside'), 'Form beside');
    assertEquals('24.0', $at('wide', 'one', 0, 'hero', 'split'), 'a split hero, at the wide measure');
    assertEquals('28.0', $at('normal', 'one', 0, 'hero', 'cover-left'), 'a cover hero keeps the narrow measure');
    assertEquals(null, SetMeasure::words($editorial, 'normal', 'one', 0, 'cta', 'beside'), 'Call to action beside, not worked out');
});

// And as the page draws them since D-184, measured again in the browser under Editorial: the
// home page's wide-left band widened and grown to 50.5rem, its quote 16.0rem and its words 32.0;
// Form beside stacked, its words the column's whole width.
test('the width of words as the page draws them, with the 16rem rules', function () {
    $editorial = Tokens::resolve(Presets::get('editorial'));
    $drawn = static fn (string $width, bool $following, string $columns, int $column, string $type, string $layout): array => SetMeasure::drawn($editorial, $width, $following, $columns, $column, $type, $layout);
    assertEquals('16.0', sprintf('%.1f', $drawn('normal', true, 'wide-left', 1, 'quote', 'plain')['words']), 'the quote of Editorial\'s home');
    assertEquals('32.0', sprintf('%.1f', $drawn('normal', true, 'wide-left', 0, 'text', 'single')['words']), 'and the words beside it');
    assertEquals([], $drawn('normal', true, 'wide-left', 1, 'quote', 'plain')['stacked'], 'side by side');
    $form = $drawn('normal', true, 'one', 0, 'form', 'beside');
    assertEquals('42.0', sprintf('%.1f', $form['words']), 'Form beside, stacked: the whole column');
    assertContains('its words stack', implode(' ', $form['stacked']), 'and said so');
    $own = $drawn('normal', false, 'wide-left', 1, 'quote', 'plain');
    assertContains('its columns stack', implode(' ', $own['stacked']), 'a width of the owner\'s is not widened: the columns stack');
});

test('bin/check-set.php prints the report and exits 1 on a refused file', function () {
    $root = dirname(__DIR__);
    $command = static function (string $file) use ($root): array {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/check-set.php') . ' ' . escapeshellarg($file) . ' 2>&1', $lines, $code);

        return [implode("\n", $lines), $code];
    };
    [$out, $code] = $command($root . '/designs/core/minimal.json');
    assertEquals(0, $code, 'exit for a good set');
    assertContains('minimal — Minimal (a character)', $out, 'what it read');
    assertContains('OK: 0 error(s)', $out, 'the verdict');

    $bad = tmpPath('check-set-bad.json');
    $set = sampleSet();
    $set['decisions']['seed'] = '#ffff00';
    file_put_contents($bad, (string) json_encode($set));
    [$out, $code] = $command($bad);
    assertEquals(1, $code, 'exit for a refused set');
    assertContains('ERROR    decisions.seed', $out, 'the error, named');
});
