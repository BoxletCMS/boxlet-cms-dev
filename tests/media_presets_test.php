<?php

use App\Modules\Media\MediaPresets;

// The arithmetic behind every cropped variant. Pure, so it is worth pinning precisely:
// a mistake here cuts heads off photographs, and does it silently.

test('the five presets of SPEC 5.5 exist and nothing else does', function () {
    assertEquals(['thumb', 'card', 'wide', 'hero', 'full'], MediaPresets::names(), 'presets');
    assertTrue(MediaPresets::exists('hero'), 'hero');
    assertTrue(!MediaPresets::exists('2400x1600'), 'a size named in a URL is not a preset');
});

test('a cropped preset takes the largest rectangle of its proportions', function () {
    // 3000x2000 landscape into a 200x200 square: the height is the limit.
    $crop = MediaPresets::crop('thumb', 3000, 2000);
    assertEquals(2000, $crop['width'], 'crop width');
    assertEquals(2000, $crop['height'], 'crop height');
    assertEquals(200, $crop['targetWidth'], 'drawn width');
    assertEquals(200, $crop['targetHeight'], 'drawn height');
    assertEquals(500, $crop['x'], 'centred horizontally by default');
    assertEquals(0, $crop['y'], 'nothing to move vertically');

    // 1000x3000 portrait into 1200x630: the width is the limit.
    $wide = MediaPresets::crop('wide', 1000, 3000);
    assertEquals(1000, $wide['width'], 'crop width');
    assertEquals(525, $wide['height'], 'crop height keeps 1200:630');
});

test('the focal point decides what stays in frame, and never hangs over an edge', function () {
    // The subject is at the top of a tall photograph: the crop follows it up.
    $top = MediaPresets::crop('thumb', 2000, 4000, 50, 10);
    assertEquals(0, $top['y'], 'a focal point near the top clamps to the top');

    $bottom = MediaPresets::crop('thumb', 2000, 4000, 50, 95);
    assertEquals(2000, $bottom['y'], 'a focal point near the bottom clamps to the last full crop');

    $middle = MediaPresets::crop('thumb', 2000, 4000, 50, 50);
    assertEquals(1000, $middle['y'], 'the middle');

    // A quarter of the way down puts the focal point in the centre of the crop.
    $quarter = MediaPresets::crop('thumb', 2000, 4000, 50, 25);
    assertEquals(0, $quarter['y'], 'a crop of 2000 cannot centre on 1000 without leaving the top');

    $sixty = MediaPresets::crop('thumb', 2000, 4000, 50, 60);
    assertEquals(1400, $sixty['y'], 'centred on 2400 minus half of 2000');
});

test('nothing is ever enlarged', function () {
    // A picture smaller than the preset stays its own size rather than going blurry.
    $small = MediaPresets::crop('thumb', 120, 90);
    assertEquals(90, $small['width'], 'crop width');
    assertEquals(90, $small['targetWidth'], 'drawn at the source size, not 200');
    assertEquals(90, $small['targetHeight'], 'drawn height');

    $full = MediaPresets::crop('full', 900, 600);
    assertEquals(900, $full['targetWidth'], 'full never enlarges either');
    assertEquals(600, $full['targetHeight'], 'proportions kept');
});

test('full keeps the proportions and bounds the width', function () {
    $full = MediaPresets::crop('full', 4000, 3000);

    assertEquals(0, $full['x'], 'no crop');
    assertEquals(4000, $full['width'], 'the whole source');
    assertEquals(2400, $full['targetWidth'], 'bounded at 2400');
    assertEquals(1800, $full['targetHeight'], '4:3 kept');
});

test('a variant\'s path is its URL, and carries the preset and the id', function () {
    assertEquals('m/card/12-harbour-at-dusk.webp', MediaPresets::file('card', 12, 'harbour-at-dusk', 'webp'), 'path');
});
