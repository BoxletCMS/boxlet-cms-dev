<?php

use App\Modules\Design\Characters;
use App\Modules\Design\Color;
use App\Modules\Design\Derived;
use App\Modules\Design\Presets;

// THE DARK BEHIND AN OPENED PICTURE (PLAN.md D-236, the owner: "dark"): under every core design
// set, light and dark, the scrim is dark, and its words and buttons read on it at 7:1.

test('an opened picture stands on a dark of the site\'s hue, its words on it at 7:1, under every set', function () {
    $off = [];
    foreach (Characters::CORE as $set) {
        foreach (['light', 'dark'] as $mode) {
            $decisions = Presets::get($set);
            $decisions['mode'] = $mode;
            $colors = Derived::from($decisions)['color'];
            [$lightness] = Color::toOklch($colors['scrim']);
            $ratio = Color::contrast($colors['on-scrim'], $colors['scrim']);
            if ($lightness > 0.25 || $ratio < 7) {
                $off[] = "{$set} {$mode}: scrim {$colors['scrim']} (L " . round($lightness, 2) . "), words " . round($ratio, 2) . ':1';
            }
        }
    }
    assertEquals([], $off, 'a scrim not dark, or words that do not read on it');
});
