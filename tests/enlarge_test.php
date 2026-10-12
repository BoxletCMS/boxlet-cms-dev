<?php

use App\Modules\Media\MediaPicture;
use App\Support\Editing;

// A PICTURE THAT OPENS LARGER ON A PRESS (PLAN.md D-236, the owner): Picture and Gallery's
// "Open larger". On, each picture is a link to its largest uncropped size, with its other
// formats, its caption and the buttons' words for site-lightbox.js, and a mark that says so at
// rest; off, nothing changes; in the editor, no link. The page loads the script only then.

/** A picture with every size a page can ask for, in AVIF, WebP and JPEG. */
function wholePicture(App\Core\Db $db, string $name): int
{
    $all = ['avif', 'webp', 'jpg'];

    return storedPicture($db, $name, [
        'card' => ['width' => 600, 'height' => 400, 'formats' => $all],
        'natural' => ['width' => 960, 'height' => 640, 'formats' => $all],
        'wide' => ['width' => 1200, 'height' => 630, 'formats' => $all],
        'full' => ['width' => 2400, 'height' => 1600, 'formats' => $all],
    ]);
}

testBothDrivers('Picture, "Open larger" on: a link to the largest uncropped size, with all it needs (D-236)', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $id = wholePicture($db, 'harbour');
    $db->query('INSERT INTO media_meta (media_id, locale, alt, caption) VALUES (?, ?, ?, ?)', [$id, 'en', 'A harbour at dawn', '']);
    $media = MediaPicture::resolve($db, 'en', [$id]);
    $registry = blockRegistry();

    $on = $registry->render('picture', ['image' => $id, 'caption' => 'Boats at rest'], [], 'full', $media, false, 'section', [], 'en', [], ['shape' => 'wide', 'enlarge' => 'on']);
    assertContains('<a class="enlarge" href="/m/full/' . $id . '-harbour.jpg', $on, 'a link to the full size, cropped or not on the page');
    assertContains('data-enlarge-avif="/m/full/' . $id . '-harbour.avif', $on, 'its AVIF');
    assertContains('data-enlarge-webp="/m/full/' . $id . '-harbour.webp', $on, 'its WebP');
    assertContains('data-width="2400" data-height="1600"', $on, 'its size');
    assertContains('data-caption="Boats at rest"', $on, 'its caption');
    assertContains('aria-label="Open larger: A harbour at dawn"', $on, 'what it is called');
    assertContains('&quot;close&quot;:&quot;Close&quot;', $on, 'the buttons\' words');
    assertContains('class="enlarge-mark"', $on, 'the mark at rest');

    $off = $registry->render('picture', ['image' => $id], [], 'full', $media, false, 'section', [], 'en', [], ['shape' => 'wide', 'enlarge' => 'off']);
    assertTrue(!str_contains($off, 'enlarge'), 'off, nothing of it');

    // In the editor a press selects the block: no link, the mark still shows the option.
    $editing = Editing::during(true, static fn (): string => $registry->render('picture', ['image' => $id], [], 'full', $media, false, 'section', [], 'en', [], ['shape' => 'natural', 'enlarge' => 'on']));
    assertTrue(!str_contains($editing, '<a class="enlarge"'), 'a link in the editor');
    assertContains('<span class="enlarge">', $editing, 'the picture marked in the editor');
});

testBothDrivers('Gallery, "Open larger" on: every picture a link; a missing one stays a placeholder (D-236)', function (string $driver) {
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski'], $driver);
    $a = wholePicture($db, 'one');
    $b = wholePicture($db, 'two');
    $media = MediaPicture::resolve($db, 'hr', [$a, $b]);
    $html = blockRegistry()->render('gallery', ['items' => [['image' => $a, 'caption' => ''], ['image' => $b, 'caption' => 'Druga'], ['image' => null, 'caption' => '']]], [], 'three', $media, false, 'section', [], 'hr', [], ['shape' => 'square', 'enlarge' => 'on']);
    assertEquals(2, substr_count($html, '<a class="enlarge"'), 'one link per picture');
    assertContains('media-placeholder', $html, 'the empty item');
    assertContains('&quot;next&quot;:&quot;Sljedeća slika&quot;', $html, 'the words in the page\'s language');
});

testBothDrivers('the page loads site-lightbox.js only where a picture opens larger (D-236)', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $id = wholePicture($db, 'shore');
    createPage($db, 'en', 'opens', 'Opens', true, [['type' => 'picture', 'content' => ['image' => $id], 'options' => ['enlarge' => 'on']]]);
    createPage($db, 'en', 'stays', 'Stays', true, [['type' => 'picture', 'content' => ['image' => $id]]]);
    assertContains('assets/site-lightbox.js', dispatch('/opens')->body, 'with a picture that opens');
    assertTrue(!str_contains(dispatch('/stays')->body, 'site-lightbox.js'), 'without one');
});
