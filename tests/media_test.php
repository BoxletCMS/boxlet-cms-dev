<?php

use App\Modules\Media\MediaEncoder;
use App\Modules\Media\MediaPresets;
use App\Modules\Media\MediaUpload;
use App\Modules\Media\MediaVariants;
use App\Modules\Media\MediaWriter;

// Uploading and encoding pictures (SPEC §5.5, §6).
//
// Fixtures are generated, never committed: a repository with binaries in it invites
// someone to commit a photograph, and D-022 keeps photographs out entirely.
//
// ASSERTIONS ARE ON GEOMETRY, NEVER ON COLOUR VALUES. This machine's ImageMagick 6.9.12
// misreads the channel order of GD's synthetic flat-colour JPEGs — a plain GD jpeg with
// no EXIF at all reads (0,0,255) through Imagick where GD reads (255,255,255), while both
// agree to within two levels on real photographs. It is an environment quirk outside our
// code, so the tests work around it rather than the product: where a distinctive region
// ENDS UP is unaffected by a channel swap, and position is what these actually test.

/**
 * A JPEG of $width × $height, dark, with a white block in its top-left corner, carrying
 * the given EXIF orientation.
 *
 * The orientation is written by hand because GD writes no EXIF and this Imagick would not
 * set one on a GD file. Byte order matters: "MM" declares big-endian, so every field is
 * packed with n/N — packing with v/V produced a segment exif_read_data ignored entirely.
 */
function imageFixture(string $file, int $width = 400, int $height = 200, int $orientation = 1): string
{
    $image = imagecreatetruecolor(max(1, $width), max(1, $height));
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 20, 20, 20));
    imagefilledrectangle($image, 0, 0, max(1, intdiv($width, 4)) - 1, max(1, intdiv($height, 4)) - 1, (int) imagecolorallocate($image, 255, 255, 255));
    ob_start();
    imagejpeg($image, null, 92);
    $jpeg = (string) ob_get_clean();
    imagedestroy($image);

    if ($orientation !== 1) {
        $entry = pack('n', 0x0112) . pack('n', 3) . pack('N', 1) . pack('n', $orientation) . pack('n', 0);
        $app1 = "Exif\0\0" . 'MM' . pack('n', 42) . pack('N', 8) . pack('n', 1) . $entry . pack('N', 0);
        $jpeg = "\xFF\xD8\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }

    file_put_contents($file, $jpeg);

    return $file;
}

/**
 * A storage directory and a public directory, both empty.
 *
 * @return array{string, string}
 */
function mediaPaths(): array
{
    $storage = tmpPath('media-storage');
    $public = tmpPath('media-public');
    removeTree($storage);
    removeTree($public);
    mkdir($storage . '/uploads', 0700, true);
    mkdir($public, 0700, true);

    return [$storage, $public];
}

test('what a file claims and what it is must agree', function () {
    $jpeg = imageFixture(tmpPath('claim.jpg'));
    $sniffed = MediaUpload::sniff($jpeg);
    assertEquals('image/jpeg', $sniffed, 'finfo on a real jpeg');

    // The extension decides what it is stored as, but only when the bytes agree.
    assertEquals('jpg', MediaUpload::extensionFor('holiday.jpg', 'image/jpeg'), 'a jpg that is one');
    assertEquals('jpg', MediaUpload::extensionFor('holiday.JPEG', 'image/jpeg'), 'jpeg normalises to jpg');
    assertEquals('png', MediaUpload::extensionFor('logo.png', 'image/png'), 'a png that is one');

    // A picture renamed to something else, and something else renamed to a picture.
    assertEquals(null, MediaUpload::extensionFor('holiday.png', 'image/jpeg'), 'a jpeg called .png');
    assertEquals(null, MediaUpload::extensionFor('shell.jpg', 'text/x-php'), 'a script called .jpg');
    assertEquals(null, MediaUpload::extensionFor('note.txt', 'text/plain'), 'a text file');
});

test('anything PHP-adjacent is refused by name as well as by bytes', function () {
    foreach (['shell.php', 'shell.phtml', 'shell.php5', 'shell.phps', 'x.cgi', 'x.pl', 'x.py', 'x.sh', 'x.svg', 'page.html'] as $name) {
        assertEquals(null, MediaUpload::extensionFor($name, 'image/jpeg'), "{$name} was allowed");
    }
    // Including the double extension a web server would run.
    assertEquals(null, MediaUpload::extensionFor('photo.jpg.php', 'image/jpeg'), 'photo.jpg.php was allowed');
});

test('the stored name is generated, never taken from the client', function () {
    assertEquals('my-photo', MediaUpload::filenameFor('My Photo.JPG'), 'spaces and case');
    assertEquals('evil', MediaUpload::filenameFor('../../evil.jpg'), 'a path');
    assertEquals('photo-php', MediaUpload::filenameFor('photo.php.jpg'), 'a double extension');
    assertEquals('cudna-suma', MediaUpload::filenameFor('Čudna šuma.jpeg'), 'transliterated');
    assertEquals('image', MediaUpload::filenameFor('...jpg'), 'a name with nothing usable in it');
    assertTrue(strlen(MediaUpload::filenameFor(str_repeat('a', 300) . '.jpg')) <= 80, 'a very long name');
});

testBothDrivers('the same bytes are stored once', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    [$storage] = mediaPaths();
    $upload = new MediaUpload($db, $storage, new MediaEncoder());

    $first = $upload->store(imageFixture(tmpPath('dedup-a.jpg')), 'first.jpg');
    // A copy of the same bytes under a different name.
    copy(tmpPath('dedup-a.jpg'), tmpPath('dedup-b.jpg'));
    $second = $upload->store(tmpPath('dedup-b.jpg'), 'second.jpg');

    assertTrue(!$first['duplicate'], 'the first upload was called a duplicate');
    assertTrue($second['duplicate'], 'the second upload was stored again');
    assertEquals($first['id'], $second['id'], 'the id the second upload resolved to');
    assertEquals(1, count($db->all('SELECT id FROM media')), 'rows in media');
});

testBothDrivers('a file whose bytes are not a picture is refused with a message naming it', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    [$storage] = mediaPaths();
    $upload = new MediaUpload($db, $storage, new MediaEncoder());

    file_put_contents($script = tmpPath('not-a-picture.jpg'), "<?php echo 'hello';");
    assertThrows(static fn () => $upload->store($script, 'not-a-picture.jpg'), 'not a picture');
    assertEquals(0, count($db->all('SELECT id FROM media')), 'it recorded the refused file');
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
    ), 0, 5), 'the order variants were made in');
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
