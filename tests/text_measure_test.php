<?php

/*
 * THE TEXT BLOCK'S LINE LENGTH (PLAN.md D-187, the owner). Its paragraphs were held to 38em
 * whatever the section's Width, and Width looked broken. The block now has a `measure` —
 * comfortable, wide, full — in one column, and the section's Width says when the text there
 * keeps its own. Questions and a Quote have one too (D-188): comfortable and wide by default.
 */

testBothDrivers('a Text block draws its line length, in two columns each column\'s', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'words', 'Words', true, [['type' => 'text', 'content' => ['body' => '<p>Words</p>']]]);
    [, $section, $blocks] = builderBand($id);
    $draw = static fn (array $block): string => json_decode(builderRequest("/admin/pages/{$id}/render", ['section' => $section, 'blocks' => [$block]])->body, true)['html'] ?? '';

    $block = ['layout' => 'single', 'options' => []] + $blocks[0];
    assertContains('class="text measure-comfortable"', $draw($block), 'the default, comfortable');
    assertContains('class="text measure-full"', $draw(['options' => ['measure' => 'full']] + $block), 'the owner\'s full width');
    // Changed deliberately (D-191, the owner): in two columns the measure is each column's.
    assertContains('class="text measure-wide"', $draw(['layout' => 'columns', 'options' => ['measure' => 'wide']] + $block), 'two columns, the measure each column\'s');
});

testBothDrivers('the section\'s Width says when the text keeps its line length, and leads to it', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'words', 'Words', true, [['type' => 'text', 'content' => ['body' => '<p>Words</p>']]]);
    [, $section, $blocks] = builderBand($id);
    $inspect = static fn (array $held): string => json_decode(builderRequest("/admin/pages/{$id}/inspect", ['kind' => 'section', 'key' => $section['key'], 'section' => $section, 'blocks' => $held, 'number' => 1])->body, true)['html'] ?? fail('no inspector');

    $block = ['layout' => 'single', 'options' => []] + $blocks[0];
    $held = $inspect([$block]);
    assertContains('data-width-measure', $held, 'a comfortable line under a section of any width');
    assertContains('data-action="select-block" data-key="' . $block['key'] . '" data-focus="measure"', $held, 'the way to the block\'s option');
    assertTrue(!str_contains($inspect([['options' => ['measure' => 'full']] + $block]), 'data-width-measure'), 'a text as wide as its section');
    assertContains('data-width-measure', $inspect([['layout' => 'columns'] + $block]), 'a text in two columns, each held to it (D-191)');
});

testBothDrivers('Questions and a Quote draw their line length in every layout, comfortable and wide by default', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'words', 'Words', true, [
        ['type' => 'accordion', 'content' => ['items' => [['question' => 'Why?', 'answer' => '<p>Because.</p>']]]],
        ['type' => 'quote', 'content' => ['quote' => 'Said.']],
    ]);
    [, $section, [$accordion]] = builderBand($id);
    [, $band, [$quote]] = builderBand($id, 1);
    $draw = static fn (array $block): string => json_decode(builderRequest("/admin/pages/{$id}/render", ['section' => $block['type'] === 'quote' ? $band : $section, 'blocks' => [$block]])->body, true)['html'] ?? '';
    [$accordion, $quote] = [['options' => []] + $accordion, ['options' => []] + $quote];

    assertContains('class="accordion measure-comfortable"', $draw($accordion), 'Questions, comfortable');
    assertContains('class="accordion measure-full"', $draw(['layout' => 'cards', 'options' => ['measure' => 'full']] + $accordion), 'as cards too');
    assertContains('class="quote measure-wide"', $draw($quote), 'a Quote, wide');
    assertContains('class="quote measure-comfortable"', $draw(['layout' => 'card', 'options' => ['measure' => 'comfortable']] + $quote), 'on a card too');

    $inspect = static fn (array $held): string => json_decode(builderRequest("/admin/pages/{$id}/inspect", ['kind' => 'section', 'key' => $band['key'], 'section' => $band, 'blocks' => $held, 'number' => 2])->body, true)['html'] ?? fail('no inspector');
    assertContains('data-action="select-block" data-key="' . $quote['key'] . '" data-focus="measure"', $inspect([$quote]), 'the section\'s Width says it of a Quote');
    assertTrue(!str_contains($inspect([['options' => ['measure' => 'full']] + $quote]), 'data-width-measure'), 'not of one as wide as its section');
});
