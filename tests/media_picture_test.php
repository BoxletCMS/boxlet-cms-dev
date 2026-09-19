<?php

use App\Core\Db;
use App\Modules\Design\SectionStyle;
use App\Modules\Media\MediaPicture;

// <picture> as a visitor receives it (SPEC §5.5).
//
// Everything here was previously unproven: every existing assertion about a block and a
// picture — blocks_test's data-media-id cases among them — passes through the PLACEHOLDER
// path, because those callers hand render() no resolved media at all. So none of them
// would have noticed if a <picture> were never emitted.

/**
 * One picture resolved the way a page resolves it.
 *
 * @return array{id: int, filename: string, width: int, height: int, focalX: int, focalY: int, variants: array<string, array{width: int, height: int, formats: list<string>}>, alt: string}
 */
function resolvedPicture(Db $db, int $id, string $locale = 'en'): array
{
    return MediaPicture::resolve($db, $locale, [$id])[$id] ?? fail("picture {$id} did not resolve");
}

testBothDrivers('a picture renders as <picture>, best format first and the original last', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $id = storedPicture($db, 'harbour', [
        'hero' => ['width' => 1920, 'height' => 1080, 'formats' => ['avif', 'webp', 'jpg']],
    ]);

    $html = MediaPicture::tag(resolvedPicture($db, $id), ['hero', 'full']);

    assertContains('<picture>', $html, 'a picture element');
    $avif = strpos($html, 'type="image/avif"');
    $webp = strpos($html, 'type="image/webp"');
    assertTrue($avif !== false && $webp !== false && $avif < $webp, 'avif does not come before webp');

    // The original format is the <img>, never also a <source>: a browser that understood
    // neither source would be offered the same file twice.
    assertTrue(!str_contains($html, 'type="image/jpeg"'), 'the original format was offered as a <source> as well');
    assertContains('m/hero/' . $id . '-harbour.jpg"', $html, 'the img points at the original format');
});

testBothDrivers('width and height are the variant output size, not the original', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $id = storedPicture($db, 'tall', [
        'card' => ['width' => 600, 'height' => 400, 'formats' => ['webp', 'jpg']],
    ]);

    $html = MediaPicture::tag(resolvedPicture($db, $id), ['card', 'wide']);

    assertContains(' width="600" height="400"', $html, 'the recorded variant size');
    assertTrue(!str_contains($html, '2400'), "the original's own width reached the markup");
});

testBothDrivers('every generated preset becomes a srcset candidate, with its width', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $id = storedPicture($db, 'street', [
        'card' => ['width' => 600, 'height' => 400, 'formats' => ['avif', 'jpg']],
        'wide' => ['width' => 1200, 'height' => 630, 'formats' => ['avif', 'jpg']],
    ]);

    $html = MediaPicture::tag(resolvedPicture($db, $id), ['card', 'wide'], '(max-width: 40rem) 100vw, 50vw');

    assertContains('m/card/' . $id . '-street.avif 600w', $html, 'the card candidate');
    assertContains('m/wide/' . $id . '-street.avif 1200w', $html, 'the wide candidate');
    assertContains('sizes="(max-width: 40rem) 100vw, 50vw"', $html, 'the sizes hint');
    // Largest last: the <img> is what a browser without srcset support downloads.
    assertContains('<img src="/m/wide/' . $id . '-street.jpg"', $html, 'the img points at the largest available preset');
});

testBothDrivers('a half-generated picture renders the presets that exist', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    // Generation reached card and ran out of budget; wide, hero and full are not made.
    $id = storedPicture($db, 'partial', [
        'card' => ['width' => 600, 'height' => 400, 'formats' => ['webp', 'jpg']],
    ]);

    $html = MediaPicture::tag(resolvedPicture($db, $id), ['card', 'wide']);

    assertContains('m/card/' . $id . '-partial.webp', $html, 'the preset that exists');
    assertTrue(!str_contains($html, 'm/wide/'), 'a URL for a variant that was never generated');
});

testBothDrivers('a picture with nothing generated renders the placeholder, never a broken URL', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $fresh = storedPicture($db, 'justuploaded', []);

    assertEquals('', MediaPicture::tag(resolvedPicture($db, $fresh), ['hero', 'full']), 'a picture with no variants');
    assertEquals('', MediaPicture::tag(null, ['hero', 'full']), 'a picture that no longer exists');

    // And through a block, which is where it matters: the grey box, not a 404.
    $registry = blockRegistry();
    $media = MediaPicture::resolve($db, 'en', [$fresh]);
    $html = $registry->render('hero', ['heading' => 'x', 'image' => $fresh], [], 'split', $media);

    assertContains('media-placeholder', $html, 'the placeholder');
    assertTrue(!str_contains($html, '<picture>'), 'a picture with no variants still drew a picture element');
});

testBothDrivers('a block renders a real picture once its variants exist', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $id = storedPicture($db, 'meadow', [
        'hero' => ['width' => 1920, 'height' => 1080, 'formats' => ['avif', 'jpg']],
    ]);
    $media = MediaPicture::resolve($db, 'en', [$id]);

    $html = blockRegistry()->render('hero', ['heading' => 'x', 'image' => $id], [], 'split', $media);

    assertContains('<picture>', $html, 'a picture element');
    assertContains('m/hero/' . $id . '-meadow.jpg', $html, 'the variant URL');
    assertTrue(!str_contains($html, 'media-placeholder'), 'the placeholder survived alongside a real picture');
});

testBothDrivers('the focal point travels as a class, rounded, never as a style attribute', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $id = storedPicture($db, 'portrait', [
        'card' => ['width' => 600, 'height' => 400, 'formats' => ['jpg']],
    ], 23, 78);

    $html = MediaPicture::tag(resolvedPicture($db, $id), ['card']);

    assertContains('focal-x-20', $html, 'x rounded to the 10% step');
    assertContains('focal-y-80', $html, 'y rounded to the 10% step');
    assertTrue(!str_contains($html, 'style='), 'a style attribute in rendered output (SPEC §5.4)');
});

testBothDrivers('alt text is the page locale, and the main language\'s where the page locale has none', function (string $driver) {
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski'], $driver);
    $id = storedPicture($db, 'dawn', [
        'card' => ['width' => 600, 'height' => 400, 'formats' => ['jpg']],
    ]);
    $db->query(
        'INSERT INTO media_meta (media_id, locale, alt, caption) VALUES (?, ?, ?, ?)',
        [$id, 'en', 'A harbour at dawn', ''],
    );

    assertContains('alt="A harbour at dawn"', MediaPicture::tag(resolvedPicture($db, $id, 'en'), ['card']), 'the page locale');

    // Croatian has no alt for this picture: it takes the main language's (D-043). This
    // said the opposite until the owner decided it — an empty alt on a picture that
    // carries meaning is worse than the source language's words. An alt left empty ON
    // PURPOSE is another matter, asserted in languages_front_test.php.
    assertContains('alt="A harbour at dawn"', MediaPicture::tag(resolvedPicture($db, $id, 'hr'), ['card']), 'a locale with no alt');
});

testBothDrivers('alt text is escaped, not injected', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $id = storedPicture($db, 'quoted', [
        'card' => ['width' => 600, 'height' => 400, 'formats' => ['jpg']],
    ]);
    $db->query(
        'INSERT INTO media_meta (media_id, locale, alt, caption) VALUES (?, ?, ?, ?)',
        [$id, 'en', 'A "quote" & <script>alert(1)</script>', ''],
    );

    $html = MediaPicture::tag(resolvedPicture($db, $id), ['card']);

    assertTrue(!str_contains($html, '<script>'), 'a raw script tag from alt text');
    assertContains('&amp;', $html, 'the ampersand was escaped');
});

testBothDrivers('everything is lazy except what the page marks eager', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $id = storedPicture($db, 'lazy', [
        'card' => ['width' => 600, 'height' => 400, 'formats' => ['jpg']],
    ]);
    $picture = resolvedPicture($db, $id);

    assertContains('loading="lazy"', MediaPicture::tag($picture, ['card']), 'a picture below the fold');
    assertTrue(!str_contains(MediaPicture::tag($picture, ['card'], '', true), 'loading="lazy"'), 'the first section was still lazy');
});

testBothDrivers('a section background is decoration, and says so', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $id = storedPicture($db, 'backdrop', [
        'wide' => ['width' => 1200, 'height' => 630, 'formats' => ['avif', 'jpg']],
    ]);
    $db->query(
        'INSERT INTO media_meta (media_id, locale, alt, caption) VALUES (?, ?, ?, ?)',
        [$id, 'en', 'A field of poppies', ''],
    );
    $media = MediaPicture::resolve($db, 'en', [$id]);
    $registry = blockRegistry();

    $behind = $registry->render('hero', ['heading' => 'x'], ['surface' => 'image', SectionStyle::IMAGE => $id], 'center', $media);

    assertContains('class="section-picture" aria-hidden="true"', $behind, 'the backdrop layer');
    assertContains('m/wide/' . $id . '-backdrop.jpg', $behind, 'the backdrop variant');
    assertContains('alt=""', $behind, 'the emptied alt');
    // Announcing the backdrop would talk over the words laid on top of it.
    assertTrue(!str_contains($behind, 'A field of poppies'), 'the backdrop read out its alt text');

    // The same picture as a block's own content keeps its alt: there it IS the content.
    $content = $registry->render('image_text', ['image' => $id, 'body' => '<p>x</p>'], [], '', $media);
    assertContains('alt="A field of poppies"', $content, 'a content picture lost its alt');
});

testBothDrivers('a background picture is drawn only under surface: image (D-024)', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $id = storedPicture($db, 'onlyimage', [
        'wide' => ['width' => 1200, 'height' => 630, 'formats' => ['jpg']],
    ]);
    $media = MediaPicture::resolve($db, 'en', [$id]);
    $registry = blockRegistry();

    $drawn = $registry->render('hero', ['heading' => 'x'], ['surface' => 'image', SectionStyle::IMAGE => $id], 'center', $media);
    assertContains('section-picture', $drawn, 'surface: image draws its picture');

    // Every other surface keeps its id — the owner can switch back — but draws nothing.
    // The words are only lifted above the backdrop by .surface-image > .container, so a
    // picture drawn under any other surface paints straight over the heading. That is
    // exactly what the demo's gradient hero did: a photograph with invisible text.
    foreach (['plain', 'tinted', 'contrast', 'gradient'] as $surface) {
        $html = $registry->render('hero', ['heading' => 'x'], ['surface' => $surface, SectionStyle::IMAGE => $id], 'center', $media);
        assertTrue(!str_contains($html, 'section-picture'), "surface: {$surface} drew a background picture");
    }
});

testBothDrivers('one pass finds pictures in block fields and behind sections, and skips the missing', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $inField = storedPicture($db, 'infield', [
        'card' => ['width' => 600, 'height' => 400, 'formats' => ['jpg']],
    ]);
    $behind = storedPicture($db, 'behind', [
        'wide' => ['width' => 1200, 'height' => 630, 'formats' => ['jpg']],
    ]);

    $media = MediaPicture::forBlocks($db, blockRegistry(), 'en', [
        ['type' => 'image_text', 'content' => ['image' => $inField], 'style' => []],
        ['type' => 'hero', 'content' => ['heading' => 'x'], 'style' => ['surface' => 'image', SectionStyle::IMAGE => $behind]],
        // A page that still names a picture someone deleted from the library.
        ['type' => 'image_text', 'content' => ['image' => 999999], 'style' => []],
    ]);

    assertTrue(isset($media[$inField]), 'the picture in a block field');
    assertTrue(isset($media[$behind]), 'the picture behind a section');
    assertTrue(!isset($media[999999]), 'a deleted picture resolved to something');
});
