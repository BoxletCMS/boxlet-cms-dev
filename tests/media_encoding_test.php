<?php

use App\Modules\Media\MediaEncoder;
use App\Modules\Media\MediaPresets;
use App\Modules\Media\MediaUpload;
use App\Modules\Media\MediaVariants;
use App\Modules\Media\MediaWriter;

// ENCODING, and the variants it produces (SPEC §5.5): what this server can really write,
// EXIF orientation, the resumable budget, and where the files land.
//
// Split from media_test.php, which passed the 300-line rule when the capability tests
// arrived. The seam is a real one: what remains there is whether an upload is ACCEPTED —
// sniffing, refusals, the generated name, deduplication — while everything here is about
// turning accepted bytes into files on disk.
//
// imageFixture() and mediaPaths() stay in media_test.php rather than moving to
// fixtures.php, which would take that file past 300 lines itself. run.php requires every
// test file before running any test, so both are defined by the time these run — the same
// arrangement media_item_test.php documents for the admin helpers.
//
// ASSERTIONS ARE ON GEOMETRY, NEVER ON COLOUR VALUES: this machine's ImageMagick 6.9.12
// misreads the channel order of GD's synthetic flat-colour JPEGs. See media_test.php.

// The defect this stands over, which cost a day of red CI. supports() asked the extension
// whether it could write AVIF — Imagick::queryFormats('AVIF'), or GD defining imageavif() —
// and took the answer on trust. On GitHub's runners both say yes and the encode then fails,
// so every picture was left half-made for ever. A capability that is declared and broken is
// ordinary on shared hosting; the only honest probe is to encode something and look.
test('a format this server claims it can write, it can really write', function () {
    $encoder = new MediaEncoder();
    if ($encoder->driver() === null) {
        skip('no encoder on this machine, so there is no claim to check', 'images');
    }

    foreach (['avif', 'webp'] as $format) {
        if (!$encoder->supports($format)) {
            continue;
        }

        // Exactly what a variant does: encode, then insist there are bytes and that
        // something can read them back as an image.
        [$storage, $public] = mediaPaths();
        $writer = new MediaWriter($encoder);
        $target = $public . '/claimed.' . $format;
        $source = imageFixture($storage . '/claimed-source.jpg', 64, 48);
        $crop = MediaPresets::crop('thumb', 64, 48);

        $result = $writer->encode($source, $target, $crop, $format, 1);

        assertTrue(is_file($target), "{$format}: supports() said yes and no file was written");
        assertTrue($result['bytes'] > 0, "{$format}: supports() said yes and the file is empty");
        assertTrue($result['width'] > 0 && $result['height'] > 0,
            "{$format}: the written file has no readable dimensions");
    }
});

// AVIF is best-effort (SPEC §5.5) and must never block. A picture that has already found it
// cannot have a format completes on what is left, rather than being offered for finishing
// for ever — which is what the library did on a host with a broken AVIF delegate.
testBothDrivers('a format recorded as unavailable no longer holds the picture back', function (string $driver) {
    [$storage, $public] = mediaPaths();
    $db = installedSite(['en' => 'English'], $driver);
    $encoder = new MediaEncoder();
    if (!$encoder->supports('webp')) {
        skip('this machine cannot write webp, so there is no set to complete', 'images');
    }
    $upload = new MediaUpload($db, $storage, $encoder);
    $variants = new MediaVariants($db, $encoder, new MediaWriter($encoder), $storage, $public);

    $id = $upload->store(imageFixture(tmpPath('besteffort.jpg'), 400, 300), 'besteffort.jpg')['id'];
    // As a host whose AVIF delegate is declared and broken leaves it.
    $db->query(
        'UPDATE media SET variants_json = ? WHERE id = ?',
        [json_encode([MediaVariants::UNAVAILABLE => ['avif']], JSON_THROW_ON_ERROR), $id],
    );

    $result = $variants->generate($id, null);

    assertTrue($result['complete'], 'a picture that cannot have AVIF never finished');
    assertEquals('complete', (string) ($db->one('SELECT status FROM media WHERE id = ?', [$id])['status'] ?? ''), 'status');
    assertEquals([], $variants->incomplete(), 'it is still being offered for finishing');
    // And it was not attempted again: nothing claims to have made an AVIF.
    assertEquals([], array_values(array_filter($result['made'],
        static fn (string $made): bool => str_ends_with($made, '.avif'))), 'it retried the unavailable format');
});

// ASSERTED ON JPEG, so that the number is proven to reach an encoder on every machine
// that can write a JPEG at all, including CI's, which cannot write AVIF. AVIF has its own
// test below, skipped where it cannot run.
test('the quality a caller asks for reaches the encoder', function () {
    $encoder = new MediaEncoder();
    if (!$encoder->supports('jpg')) {
        skip('this machine cannot write jpeg, so there is no quality to observe', 'images');
    }
    $writer = new MediaWriter($encoder);
    $source = noiseFixture(tmpPath('quality.jpg'), 800, 600);
    $crop = MediaPresets::crop('card', 800, 600);

    $high = $writer->encode($source, tmpPath('quality-high.jpg'), $crop, 'jpg', 1, 90);
    $low = $writer->encode($source, tmpPath('quality-low.jpg'), $crop, 'jpg', 1, 20);

    assertTrue(
        $low['bytes'] < $high['bytes'],
        sprintf('quality 20 gave %d bytes and quality 90 gave %d: the parameter is being dropped', $low['bytes'], $high['bytes']),
    );
});

// AVIF's own quality reaching its encoder (PLAN.md O-18). On ImageMagick 6.9.12 it was
// dropped for years of this project's life because only the image-level setter was
// called; the size guard below was a no-op on every Imagick host because of it.
test('the quality asked for an AVIF reaches its encoder', function () {
    $encoder = new MediaEncoder();
    if (!$encoder->supports('avif')) {
        skip('this machine cannot write avif, so there is no quality to observe', 'avif');
    }
    $writer = new MediaWriter($encoder);
    $source = noiseFixture(tmpPath('avif-quality.jpg'), 800, 600);
    $crop = MediaPresets::crop('card', 800, 600);

    $high = $writer->encode($source, tmpPath('avif-quality-high.avif'), $crop, 'avif', 1, 70);
    $low = $writer->encode($source, tmpPath('avif-quality-low.avif'), $crop, 'avif', 1, 20);

    assertTrue(
        $low['bytes'] < $high['bytes'],
        sprintf('avif quality 20 gave %d bytes and quality 70 gave %d: the parameter is being dropped', $low['bytes'], $high['bytes']),
    );
});

// The retry (SPEC §8) through the claims that hold on EVERY driver: a retry never makes
// things worse and never leaves its working file behind — the two ways this could damage
// a library rather than merely fail to help it. That it helps is the test above.
testBothDrivers('an oversized AVIF is never replaced by a larger one, and leaves no working file', function (string $driver) {
    [$storage, $public] = mediaPaths();
    $db = installedSite(['en' => 'English'], $driver);
    $encoder = new MediaEncoder();
    // 'avif', NOT 'images'. This skip cost a red CI: GitHub's runners have image support
    // and cannot write AVIF — the delegate declares it and fails, which is the defect
    // canReallyWrite() stands over — so TEST_REQUIRE_IMAGES=1 turned an honest skip into
    // four failing jobs. AVIF is best-effort by SPEC §5.5 and no build is required to
    // have it, so it gets a capability of its own that nothing sets.
    if (!$encoder->supports('avif')) {
        skip('this machine cannot write avif, so the rule never applies', 'avif');
    }
    $upload = new MediaUpload($db, $storage, $encoder);
    $variants = new MediaVariants($db, $encoder, new MediaWriter($encoder), $storage, $public);

    // Noise, because the rule only engages over 200 KB and a flat fixture is a few KB.
    $source = noiseFixture(tmpPath('retry.jpg'), 2000, 1200);
    $direct = (new MediaWriter($encoder))->encode(
        $source,
        tmpPath('retry-direct.avif'),
        MediaPresets::crop('hero', 2000, 1200),
        'avif',
        1,
    );
    // No skip when the noise comes out small. Whether the rule engages depends on the
    // encoder, and both claims below hold either way — trivially when it does not run.
    // The first version skipped here under the 'images' capability, which is not what
    // this condition is about at all.
    $engaged = $direct['bytes'] > 200 * 1024;

    $id = $upload->store($source, 'retry.jpg')['id'];
    $variants->generate($id, null);

    $stored = glob($public . '/m/hero/*-retry.avif') ?: [];
    if ($stored === []) {
        fail('no hero avif was written at all');
    }
    assertTrue(
        (int) filesize($stored[0]) <= $direct['bytes'],
        sprintf(
            'the stored hero is %d bytes, larger than the %d a single encode gives (the rule %s)',
            (int) filesize($stored[0]),
            $direct['bytes'],
            $engaged ? 'engaged' : 'did not engage on this encoder',
        ),
    );
    assertEquals([], glob($public . '/m/*/*.retry') ?: [], 'a retry left its working file in the public directory');
});

test('a full-size AVIF is retried against a budget scaled by its pixels, a cropped one against 200 KB', function () {
    assertEquals(200 * 1024, MediaVariants::retryOver('hero', 1920, 1080), 'hero');
    assertEquals(200 * 1024, MediaVariants::retryOver('card', 800, 600), 'a small cropped preset keeps the same figure');
    assertEquals(200 * 1024, MediaVariants::retryOver('full', 1000, 700), 'a small full never falls under it');
    // §8's photograph at full, 2400×1590: 1.84 heroes of pixels, so about 368 KB. It was
    // served at 707 KB before (O-35).
    assertEquals((int) round(200 * 1024 * 2400 * 1590 / (1920 * 1080)), MediaVariants::retryOver('full', 2400, 1590), 'a large full');
});

testBothDrivers('a heavy full-size AVIF is retried too, and is never replaced by a larger one', function (string $driver) {
    [$storage, $public] = mediaPaths();
    $db = installedSite(['en' => 'English'], $driver);
    $encoder = new MediaEncoder();
    if (!$encoder->supports('avif')) {
        skip('this machine cannot write avif, so the rule never applies', 'avif');
    }
    $upload = new MediaUpload($db, $storage, $encoder);
    $variants = new MediaVariants($db, $encoder, new MediaWriter($encoder), $storage, $public);

    // Noise at 2600 wide, so `full` is 2400 across and heavy enough to engage the rule.
    $source = noiseFixture(tmpPath('retry-full.jpg'), 2600, 1600);
    $direct = (new MediaWriter($encoder))->encode($source, tmpPath('retry-full-direct.avif'), MediaPresets::crop('full', 2600, 1600), 'avif', 1);

    $id = $upload->store($source, 'retry-full.jpg')['id'];
    $variants->generate($id, null);

    $stored = glob($public . '/m/full/*-retry-full.avif') ?: [];
    if ($stored === []) {
        fail('no full avif was written at all');
    }
    $engaged = $direct['bytes'] > MediaVariants::retryOver('full', $direct['width'], $direct['height']);
    // Where the rule engages, the stored file must be the retry's: smaller than one encode.
    // Where it does not, it is that one encode. Both hold on every driver.
    if ($engaged) {
        assertTrue((int) filesize($stored[0]) < $direct['bytes'], sprintf('the stored full is %d bytes, not under the %d a single encode gives', (int) filesize($stored[0]), $direct['bytes']));
    } else {
        assertTrue((int) filesize($stored[0]) <= $direct['bytes'], 'the stored full is larger than a single encode');
    }
    assertEquals([], glob($public . '/m/*/*.retry') ?: [], 'a retry left its working file in the public directory');
});

// The orientation case, tested by GEOMETRY. A 400×200 source with a white block in its
// stored top-left, tagged orientation 6 (a quarter turn clockwise on display), presents
// as 200×400 — and a crop taken after the turn is a crop of the upright picture.
test('EXIF orientation is applied before the crop, not after', function () {
    $encoder = new MediaEncoder();
    $source = imageFixture(tmpPath('orient.jpg'), 400, 200, 6);

    assertEquals(6, MediaEncoder::orientationOf($source), 'the orientation was not read back');

    // inspect() reports the size a person sees, which is the turned one.
    $info = $encoder->inspect($source);
    assertEquals(200, $info['width'], 'upright width');
    assertEquals(400, $info['height'], 'upright height');

    // Cropping BEFORE turning would use 400×200 and give a landscape crop; the
    // measurements below are only possible if the turn happened first.
    $crop = MediaPresets::crop('full', $info['width'], $info['height'], 50, 50);
    $written = (new MediaWriter($encoder))->encode(
        $source,
        $target = tmpPath('media-public') . '/orient-out.png',
        $crop,
        'png',
        MediaEncoder::orientationOf($source),
    );

    assertEquals(200, $written['width'], 'the written variant is not upright');
    assertEquals(400, $written['height'], 'the written variant is not upright');
    assertTrue(is_file($target), 'nothing was written');
});

test('an untagged picture is left alone', function () {
    $encoder = new MediaEncoder();
    $source = imageFixture(tmpPath('upright.jpg'), 400, 200);

    assertEquals(1, MediaEncoder::orientationOf($source), 'a file with no tag');
    $info = $encoder->inspect($source);
    assertEquals(400, $info['width'], 'width');
    assertEquals(200, $info['height'], 'height');
});

testBothDrivers('a budget stops the set part-way, and continuing finishes it', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    [$storage, $public] = mediaPaths();
    $encoder = new MediaEncoder();
    $upload = new MediaUpload($db, $storage, $encoder);
    $variants = new MediaVariants($db, $encoder, new MediaWriter($encoder), $storage, $public);

    $id = $upload->store(imageFixture(tmpPath('budget.jpg'), 1200, 800), 'budget.jpg')['id'];

    // A budget smaller than the reserve stops before the first encode: nothing is made,
    // and the row says so rather than claiming to be finished.
    $none = $variants->generate($id, 0.1);
    assertEquals([], $none['made'], 'it encoded something inside an impossible budget');
    assertTrue(!$none['complete'], 'it called an empty set complete');
    assertEquals(['incomplete'], array_column($db->all('SELECT status FROM media WHERE id = ' . $id), 'status'), 'status');
    assertTrue(in_array($id, $variants->incomplete(), true), 'it is not offered for finishing');

    // No budget: the rest is made, and the row becomes complete.
    $rest = $variants->generate($id, null);
    assertTrue($rest['complete'], 'the unbounded run did not complete the set');
    assertEquals([], $variants->incomplete(), 'something is still waiting');

    // Priority order: the cheapest and most needed exist first.
    assertEquals(MediaVariants::ORDER, array_slice(array_map(
        static fn (string $made): string => explode('.', $made)[0],
        array_values(array_unique(array_map(
            static fn (string $made): string => explode('.', $made)[0],
            $rest['made'],
        ))),
    // Counted from ORDER: this read 5, the number of presets, until D-119 made it six.
    ), 0, count(MediaVariants::ORDER)), 'the order variants were made in');
});

testBothDrivers('variants land where their URL says they do', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    [$storage, $public] = mediaPaths();
    $encoder = new MediaEncoder();
    $upload = new MediaUpload($db, $storage, $encoder);
    $variants = new MediaVariants($db, $encoder, new MediaWriter($encoder), $storage, $public);

    $id = $upload->store(imageFixture(tmpPath('paths.jpg'), 1200, 800), 'A Nice Photo.jpg')['id'];
    $variants->generate($id, null);

    $media = $db->one('SELECT * FROM media WHERE id = ?', [$id]);
    if ($media === null) {
        fail('the uploaded picture has no row');
    }
    assertEquals('a-nice-photo', $media['filename'], 'the generated filename');

    $described = MediaVariants::of($media);
    foreach (MediaVariants::ORDER as $preset) {
        assertTrue(isset($described[$preset]), "{$preset} is missing from variants_json");
        foreach ($described[$preset]['formats'] as $format) {
            $path = MediaPresets::file($preset, $id, 'a-nice-photo', $format);
            assertEquals("m/{$preset}/{$id}-a-nice-photo.{$format}", $path, 'the path shape');
            assertTrue(is_file($public . '/' . $path), "{$path} was recorded but not written");
        }
        // The recorded size is the true output size, which <picture> needs.
        assertTrue($described[$preset]['width'] > 0 && $described[$preset]['height'] > 0, "{$preset} recorded no size");
    }

    // The original is outside the web root, untouched, and not among the variants.
    assertTrue(is_file($storage . '/' . $media['path']), 'the original was not kept');
    assertTrue(!str_contains((string) $media['path'], 'public'), 'the original is inside the web root');
});
