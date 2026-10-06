<?php

use App\Modules\Design\SetCheck;

/*
 * A DIVIDER ON EVERY SECTION, SAID (PLAN.md D-198, the owner). A divider is an accent on the
 * transitions a character chooses (composition_test holds every core character to it); the
 * Zine the owner sent in D-197 drew a line on every section, and check-set said nothing. It
 * says so now, by the same reckoning: a block type's own divider, else the section's.
 */

test('check-set warns of a divider on every section, and not of a divider as an accent', function () {
    $set = json_decode((string) file_get_contents(dirname(__DIR__) . '/designs/core/minimal.json'), true);
    $warned = static fn (array $set): array => array_values(array_filter(SetCheck::check((string) json_encode($set), blockRegistry())['warnings'], static fn (string $w): bool => str_contains($w, 'a divider on every section')));

    $set['composition']['section']['divider'] = 'none';
    $set['composition']['dividers'] = ['quote' => 'line', 'cta' => 'line'];
    assertEquals([], $warned($set), 'lines on the quote and the call: an accent');

    $set['composition']['section']['divider'] = 'line';
    $set['composition']['dividers'] = ['quote' => 'slant', 'cta' => 'slant'];
    $said = $warned($set);
    assertEquals(1, count($said), 'a line on every other section');
    assertContains('section.divider line', $said[0] ?? '', 'and which');

    $set['composition']['dividers']['text'] = 'none';
    assertEquals([], $warned($set), 'one block type left without: not every section, as the core rule counts');
});
