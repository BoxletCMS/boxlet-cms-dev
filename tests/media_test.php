<?php

use App\Modules\Media\MediaEncoder;
use App\Modules\Media\MediaFileType;
use App\Modules\Media\MediaUpload;

// Uploading pictures: what is ACCEPTED and what is refused (SPEC §5.5, §6). Turning
// accepted bytes into files on disk is media_encoding_test.php, split out when this file
// passed the 300-line rule.
//
// Fixtures are generated, never committed: a repository with binaries in it invites
// someone to commit a photograph, and D-022 keeps photographs out entirely.
//
// imageFixture() and mediaPaths() live here and are used by every media test file. They
// stayed rather than moving to fixtures.php, which would take that file past 300 lines of
// its own. run.php requires every test file before running any test, so both are defined
// by the time the others run.
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
    // GD specifically, not "an encoder": this manufactures its JPEG with imagecreatetruecolor
    // and imagejpeg, so an Imagick-only host cannot run it either and saying "no encoder"
    // there would be false. Every test that needs a picture to exist comes through here,
    // which is why this one gate covers all of them.
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        skip('GD is not installed, so no picture fixture can be made', 'images');
    }

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
 * A JPEG of $width × $height filled with random pixels.
 *
 * Beside imageFixture() rather than a flag on it, because the two exist for opposite
 * reasons and a caller should not have to read a boolean to know which it is getting.
 * imageFixture() is a flat dark rectangle with a white corner: it tests where a region
 * ENDS UP, and it compresses to a few kilobytes. Noise compresses to almost nothing
 * else, which is the only way to make an encoder produce a variant big enough to reach
 * the size rules in SPEC §8 — a test using the flat fixture for that would pass without
 * the code under test ever running.
 *
 * Coarse blocks rather than per-pixel noise: 1920×1080 individual calls take seconds,
 * and 8px cells are already far past what any codec can predict.
 */
function noiseFixture(string $file, int $width = 2000, int $height = 1200): string
{
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        skip('GD is not installed, so no picture fixture can be made', 'images');
    }

    $image = imagecreatetruecolor(max(1, $width), max(1, $height));
    mt_srand(20260917);
    for ($y = 0; $y < $height; $y += 8) {
        for ($x = 0; $x < $width; $x += 8) {
            $colour = (int) imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagefilledrectangle($image, $x, $y, min($x + 7, $width - 1), min($y + 7, $height - 1), $colour);
        }
    }
    ob_start();
    imagejpeg($image, null, 95);
    $jpeg = (string) ob_get_clean();
    imagedestroy($image);
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

/**
 * A real 1×1 PNG, as bytes. Not imageFixture(): that needs GD, and these are exactly the
 * tests that must run on a machine without it. finfo only has to agree it is a PNG, because
 * an upload is refused for want of an encoder before anything inspects the pixels.
 */
function pngBytes(string $file): string
{
    file_put_contents($file, (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    ));

    return $file;
}

// A host with neither GD nor Imagick is ordinary shared hosting, and CI now runs the whole
// suite on one. Before this, such a host took the upload: getimagesize is core rather than
// GD, so inspect() succeeded, the original was stored and a row written — and then nothing
// could be generated. The owner got a library card with no thumbnail and nothing saying why.
test('a server that cannot process pictures refuses the upload, and says what to ask for', function () {
    [$storage] = mediaPaths();
    $db = installedSite();
    $upload = new MediaUpload($db, $storage, new MediaEncoder(MediaEncoder::NONE));

    assertThrows(
        static fn () => $upload->store(pngBytes(tmpPath('no-encoder.png')), 'photo.png'),
        'neither GD nor Imagick',
    );

    // Refused BEFORE anything was written: no row, and no original left behind.
    assertEquals(0, count($db->all('SELECT id FROM media')), 'rows in media');
    assertEquals([], glob($storage . '/uploads/*') ?: [], 'files in storage/uploads');
});

test('a server that cannot process pictures refuses a replacement too, leaving the original', function () {
    [$storage] = mediaPaths();
    $db = installedSite();
    $db->query(
        'INSERT INTO media (filename, original_name, path, mime, size, width, height, hash, created_at, status, variants_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        ['kept', 'kept.jpg', 'uploads/kept.jpg', 'image/jpeg', 10, 400, 200, 'hash-kept', '2026-01-01 00:00:00', 'complete',
            '{"thumb":{"formats":["webp"],"width":200,"height":200}}'],
    );
    $id = (int) $db->lastInsertId();
    $upload = new MediaUpload($db, $storage, new MediaEncoder(MediaEncoder::NONE));

    // replace() clears variants_json and deletes the old original, so accepting bytes this
    // server cannot process would destroy a working picture to put an unusable one in its
    // place. It has to refuse before touching anything.
    assertThrows(
        static fn () => $upload->replace($id, pngBytes(tmpPath('no-encoder-replace.png')), 'new.png'),
        'neither GD nor Imagick',
    );

    $row = $db->one('SELECT * FROM media WHERE id = ?', [$id]);
    // Guarded, not asserted: assertTrue() narrows nothing for the analyser, so indexing the
    // row after one is an offset it cannot know exists. fail() returns never, which it can.
    if ($row === null || !array_key_exists('variants_json', $row)) {
        fail('the picture vanished, or its row has no variants_json');
    }
    assertEquals('uploads/kept.jpg', (string) ($row['path'] ?? ''), 'the original it still points at');
    assertEquals('complete', (string) ($row['status'] ?? ''), 'status');
    // Still there, untouched. variants_json is nullable, so a refused replacement that had
    // cleared it would read identically to one that never touched it under `?? null`.
    assertTrue(is_string($row['variants_json']), 'the variants it already had were cleared by a refused replacement');
});

test('what a file claims and what it is must agree', function () {
    $jpeg = imageFixture(tmpPath('claim.jpg'));
    $sniffed = MediaFileType::sniff($jpeg);
    assertEquals('image/jpeg', $sniffed, 'finfo on a real jpeg');

    // The extension decides what it is stored as, but only when the bytes agree.
    assertEquals('jpg', MediaFileType::extensionFor('holiday.jpg', 'image/jpeg'), 'a jpg that is one');
    assertEquals('jpg', MediaFileType::extensionFor('holiday.JPEG', 'image/jpeg'), 'jpeg normalises to jpg');
    assertEquals('png', MediaFileType::extensionFor('logo.png', 'image/png'), 'a png that is one');

    // A picture renamed to something else, and something else renamed to a picture.
    assertEquals(null, MediaFileType::extensionFor('holiday.png', 'image/jpeg'), 'a jpeg called .png');
    assertEquals(null, MediaFileType::extensionFor('shell.jpg', 'text/x-php'), 'a script called .jpg');
    assertEquals(null, MediaFileType::extensionFor('note.txt', 'text/plain'), 'a text file');
});

test('anything PHP-adjacent is refused by name as well as by bytes', function () {
    foreach (['shell.php', 'shell.phtml', 'shell.php5', 'shell.phps', 'x.cgi', 'x.pl', 'x.py', 'x.sh', 'x.svg', 'page.html'] as $name) {
        assertEquals(null, MediaFileType::extensionFor($name, 'image/jpeg'), "{$name} was allowed");
    }
    // Including the double extension a web server would run.
    assertEquals(null, MediaFileType::extensionFor('photo.jpg.php', 'image/jpeg'), 'photo.jpg.php was allowed');
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

