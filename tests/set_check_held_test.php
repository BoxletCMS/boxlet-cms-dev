<?php

use App\Modules\Design\SetCheck;

/*
 * A COLOUR BY HAND WITHOUT A DARK VERSION, SAID (PLAN.md D-192, the owner). A colour by hand
 * holds in both modes (D-187), so a set that gives the page, a card, the surface or the text a
 * colour of its own and no `dark` block keeps it in dark mode: measured, Terra's sand page was
 * its dark mode byte for byte. check-set says so for each, with what it does.
 */

test('check-set warns of a colour by hand that dark mode would keep, and not once the set has a dark version', function () {
    $set = json_decode((string) file_get_contents(dirname(__DIR__) . '/designs/core/minimal.json'), true);
    unset($set['dark']);
    $warnings = static fn (array $set): array => array_values(array_filter(SetCheck::check((string) json_encode($set), blockRegistry())['warnings'], static fn (string $w): bool => str_contains($w, 'set by hand and the set has no dark version')));

    assertEquals([], $warnings($set), 'no colour by hand, nothing said');

    $set['decisions']['color_background'] = '#f7f2ec';
    $said = $warnings($set);
    assertEquals(1, count($said), 'the page colour');
    assertContains('in dark mode the page stays this colour, so dark mode stays light', $said[0] ?? '', 'and what it does');

    $set['decisions']['color_text'] = '#21110d';
    assertEquals(2, count($warnings($set)), 'the text too, each its own');

    $set['dark'] = ['color_background' => '#1d1916'];
    assertEquals([], $warnings($set), 'a dark version: its colours are worked out for the dark page');
});
