<?php

use App\Modules\Design\Color;
use App\Modules\Design\Palette;

/*
 * ON A DARK PAGE THE GRADIENT STAYS A DEEP BAND (PLAN.md D-194, the owner). A set's dark version
 * names a light main colour for its links — Launch's #a594ff — and the gradient made of it was a
 * pale lilac band with dark words in the middle of a dark page. It is walked down until the
 * page's light text reads on both its ends; a light page's gradient is the seed's as it was.
 */

test('on a dark page a light main colour makes a deep gradient under the light text', function () {
    $dark = Palette::colors('#a594ff', '#1a1440', 24, [], 'dark');
    assertEquals($dark['text'], $dark['on-gradient'], 'the light text on the gradient, not the page\'s dark ink');
    foreach (['gradient-start', 'gradient-end'] as $end) {
        assertTrue(Color::contrast($dark['on-gradient'], $dark[$end]) >= Palette::AA_BODY, "the text reads on the gradient's {$end}");
    }
    assertTrue(Color::toOklch($dark['gradient-start'])[0] < Color::toOklch('#a594ff')[0], 'the start is deeper than the main colour');
    assertTrue(abs(Color::toOklch($dark['gradient-start'])[2] - Color::toOklch('#a594ff')[2]) < 3, 'and of its hue');

    // THE CONTROL: the same colour on a light page is the gradient's start as it always was.
    $light = Palette::colors('#a594ff', '#1a1440', 24, [], 'light');
    assertEquals('#a594ff', $light['gradient-start'], 'a light page keeps the seed');
    assertEquals($light['text'], $light['on-gradient'], 'and the page\'s dark text on it, which reads there');
});

test('on a dark page a main colour that already reads under the light text is kept', function () {
    $dark = Palette::colors('#6d28d9', '', 20, [], 'dark');
    assertEquals('#6d28d9', $dark['gradient-start'], 'Bold\'s violet kept');
    assertEquals($dark['text'], $dark['on-gradient'], 'under the light text');
});
