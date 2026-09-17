<?php

use App\Modules\Media\MediaAlt;
use App\Modules\Media\MediaEncoder;
use App\Modules\Media\MediaMeta;
use App\Modules\Media\MediaUpload;

// SUGGESTED ALT TEXT (PLAN.md D-025): what a picture says before anyone has said anything
// about it.
//
// The risky part is not reading the metadata, it is the two LISTS — the strings a camera
// writes into a title field, and the shapes of a device-generated file name. A pattern
// that is too eager silently eats a description someone really wrote; one that is too
// timid has a screen reader announce "IMG 4032". Both failures are invisible unless
// something asserts them case by case, so every entry in either list is named here.

/**
 * One IPTC record: 0x1C, record 2, the dataset, a big-endian length, then the value.
 */
function iptcRecord(int $dataset, string $value): string
{
    return chr(0x1C) . chr(2) . chr($dataset) . pack('n', strlen($value)) . $value;
}

/**
 * A JPEG carrying IPTC fields. iptcembed() is part of PHP itself rather than an image
 * extension, so this fixture works on a host with no GD and no Imagick.
 *
 * @param array<int, string> $fields dataset number => value
 */
function iptcFixture(string $file, array $fields, string $sourceName = 'iptc-source.jpg'): string
{
    $source = imageFixture(tmpPath($sourceName), 64, 48);
    $iptc = '';
    foreach ($fields as $dataset => $value) {
        $iptc .= iptcRecord($dataset, $value);
    }

    $embedded = iptcembed($iptc, $source);
    file_put_contents($file, is_string($embedded) ? $embedded : (string) file_get_contents($source));

    return $file;
}

/**
 * A JPEG carrying an EXIF ImageDescription, assembled by hand.
 *
 * The same reasoning as imageFixture's orientation tag: GD writes no EXIF. "MM" declares
 * big-endian, so every field is packed with n/N. An ASCII value longer than four bytes
 * does not fit in the entry, so the entry holds an OFFSET to it — 26 here, which is the
 * 8-byte TIFF header plus a one-entry IFD (2 + 12 + 4).
 */
function exifDescriptionFixture(string $file, string $description): string
{
    $source = imageFixture(tmpPath('exif-source.jpg'), 64, 48);
    $jpeg = (string) file_get_contents($source);

    $value = $description . "\0";
    $entry = pack('n', 0x010E) . pack('n', 2) . pack('N', strlen($value)) . pack('N', 26);
    $ifd = pack('n', 1) . $entry . pack('N', 0);
    $app1 = "Exif\0\0" . 'MM' . pack('n', 42) . pack('N', 8) . $ifd . $value;

    file_put_contents($file, "\xFF\xD8\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2));

    return $file;
}

test('a title in the file metadata is preferred to the file name', function () {
    // Both are meaningful. The one a person typed into the picture wins over the one they
    // typed into a save dialog.
    $file = iptcFixture(tmpPath('headline.jpg'), [105 => 'Fishing boats at Kaštela']);

    assertEquals('Fishing boats at Kaštela', MediaAlt::suggest($file, 'tim-u-uredu_2024.jpg'), 'headline');
});

test('the object name is used when there is no headline', function () {
    $file = iptcFixture(tmpPath('objectname.jpg'), [5 => 'Studio portrait, Split']);

    assertEquals('Studio portrait, Split', MediaAlt::suggest($file, 'IMG_4032.jpg'), 'object name');
});

test('the headline is preferred to the object name', function () {
    $file = iptcFixture(tmpPath('both.jpg'), [5 => 'Object name', 105 => 'Headline']);

    assertEquals('Headline', MediaAlt::fromMetadata($file), 'headline wins');
});

// Every string in MediaAlt::GENERIC, named. A camera writing "OLYMPUS DIGITAL CAMERA" into
// the title has said nothing, and reading it aloud is worse than silence — so it falls
// through to the file name rather than being used.
test('a generic camera string is not a description', function () {
    foreach ([
        'OLYMPUS DIGITAL CAMERA',
        'SONY DSC',
        'KONICA MINOLTA DIGITAL CAMERA',
        'MINOLTA DIGITAL CAMERA',
        'NIKON DIGITAL CAMERA',
        'CASIO COMPUTER CO.,LTD.',
        'Exif_JPEG_PICTURE',
        'DCIM',
        'Untitled',
        'default',
    ] as $generic) {
        $file = iptcFixture(tmpPath('generic.jpg'), [105 => $generic], 'generic-source.jpg');
        assertEquals('', MediaAlt::fromMetadata($file), "\"{$generic}\" was treated as a description");

        // And the file name is used instead, which is the point of ignoring it.
        assertEquals('Tim u uredu 2024', MediaAlt::suggest($file, 'tim-u-uredu_2024.jpg'), 'the fallback');
    }
});

test('a file name becomes a description, tidied', function () {
    foreach ([
        'tim-u-uredu_2024.jpg' => 'Tim u uredu 2024',
        'My Photo.JPG' => 'My Photo',
        'harbour.at.dawn.png' => 'Harbour at dawn',
        'brodovi--u--luci.webp' => 'Brodovi u luci',
        '  spaced out  .jpeg' => 'Spaced out',
        'Čudna šuma.jpg' => 'Čudna šuma',
        'kava_i_kolač.png' => 'Kava i kolač',
        // A path is a name, not a directory to walk.
        '../../secret/holiday photo.jpg' => 'Holiday photo',
        // Already capitalised inside the first word: left as the owner typed it.
        'iPhone case.jpg' => 'iPhone case',
        'eBay listing.png' => 'eBay listing',
    ] as $name => $expected) {
        assertEquals($expected, MediaAlt::fromName($name), "tidying \"{$name}\"");
    }
});

// Every shape in MediaAlt::DEVICE, named. This is the list that protects someone from
// being read a camera's filing system.
test('a device-generated name suggests nothing', function () {
    // A REAL picture with no metadata in it, not a path to nothing: getimagesize on a
    // missing file raises, and the harness turns warnings into exceptions, so the earlier
    // version of this failed on the fixture rather than on the thing being tested.
    $plain = imageFixture(tmpPath('no-metadata.jpg'), 64, 48);

    foreach ([
        'IMG_4032.jpg',
        'IMG-4032.jpg',
        'IMG 4032.jpg',
        'img_0001.jpeg',
        'DSC_0012.jpg',
        'DSCN0012.jpg',
        'DSCF1234.jpg',
        'PXL_20240101_123456789.jpg',
        'MVIMG_20240101_123456.jpg',
        'VID_20240101_123456.jpg',
        'PANO_20240101.jpg',
        'BURST001.jpg',
        '20240101_123456.jpg',
        '2024-01-01 12.34.56.jpg',
        '2024_01_01.png',
        'Screenshot 2024-01-01 at 12.34.56.png',
        'Screen Shot 2024-01-01.png',
        'Snímek obrazovky 2024-01-01.png',
        'Bildschirmfoto 2024-01-01.png',
        'WhatsApp Image 2024-01-01 at 12.34.56.jpeg',
        'Signal-2024-01-01-123456.jpg',
        '20240101.jpg',
        '4032.jpg',
        'a3f9c81b2d4e5f60.jpg',
        '7f3e4d2a-1b9c-4e8f-a7d6-0c1b2a3d4e5f.png',
    ] as $name) {
        assertEquals('', MediaAlt::fromName($name), "\"{$name}\" was turned into a description");
        // And nothing is invented from the metadata either, because there is none.
        assertEquals('', MediaAlt::suggest($plain, $name), "\"{$name}\" through suggest()");
    }
});

test('a suggestion is trimmed of control characters and cut to the column', function () {
    $file = iptcFixture(tmpPath('control.jpg'), [105 => "A harbour\r\nat\tdawn"], 'control-source.jpg');
    assertEquals('A harbour at dawn', MediaAlt::fromMetadata($file), 'control characters');

    // NOT a repeated 'a': that is a hex string, so the device-name check would return ''
    // and this would pass without the cut ever happening. Real words, and long ones.
    $long = trim(str_repeat('ribarski brodovi u luci ', 30));
    $cut = MediaAlt::fromName($long . '.jpg');
    assertTrue($cut !== '', 'a long but ordinary name was thrown away');
    assertTrue(strlen($cut) <= 255, 'a very long name was not cut to the column: ' . strlen($cut));
});

test('an EXIF description is used when there is no IPTC', function () {
    if (!function_exists('exif_read_data')) {
        skip('no exif extension, so an EXIF description cannot be read', 'exif');
    }
    $file = exifDescriptionFixture(tmpPath('exifdesc.jpg'), 'Olive grove above Trogir');

    assertEquals('Olive grove above Trogir', MediaAlt::fromMetadata($file), 'ImageDescription');
});

testBothDrivers('an uploaded picture is given a suggestion, marked as one', function (string $driver) {
    [$storage] = mediaPaths();
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski'], $driver);
    $encoder = new MediaEncoder();
    $upload = new MediaUpload($db, $storage, $encoder);

    $id = $upload->store(imageFixture(tmpPath('tim-u-uredu_2024.jpg'), 320, 240), 'tim-u-uredu_2024.jpg')['id'];

    $row = $db->one('SELECT alt, alt_suggested, locale FROM media_meta WHERE media_id = ?', [$id]);
    if ($row === null) {
        fail('no alt was suggested at all');
    }
    assertEquals('Tim u uredu 2024', (string) $row['alt'], 'the suggested alt');
    assertEquals(1, (int) $row['alt_suggested'], 'it is not marked as a suggestion');
    // The primary locale only: a guess in one language is not a guess in another.
    assertEquals('en', (string) $row['locale'], 'the locale it was written for');
    assertEquals(1, count($db->all('SELECT id FROM media_meta WHERE media_id = ' . $id)), 'rows in media_meta');
});

// The LIBRARY's path to the same fact, which is a different one from the picture screen's:
// the card asks suggestedIds() once for the whole listing, while the screen asks
// forPicture() for one. Tested here because the browser scenario can only assert the card
// on a genuinely new upload, and identical bytes are recognised and returned as they stand.
testBothDrivers('the library is told which pictures still carry a guess', function (string $driver) {
    [$storage] = mediaPaths();
    $db = installedSite(['en' => 'English'], $driver);
    $upload = new MediaUpload($db, $storage, new MediaEncoder());

    $guessed = $upload->store(imageFixture(tmpPath('brodovi-u-luci.jpg'), 320, 240), 'brodovi-u-luci.jpg')['id'];
    $device = $upload->store(imageFixture(tmpPath('IMG_7781.jpg'), 300, 200), 'IMG_7781.jpg')['id'];
    $confirmed = $upload->store(imageFixture(tmpPath('kava-na-terasi.jpg'), 280, 180), 'kava-na-terasi.jpg')['id'];
    MediaMeta::save($db, $confirmed, 'en', 'Kava na terasi', '');

    $suggested = MediaAlt::suggestedIds($db, [$guessed, $device, $confirmed]);

    assertTrue(isset($suggested[$guessed]), 'a picture Boxlet named itself is not offered for checking');
    assertTrue(!isset($suggested[$device]), 'a device-named picture was marked as a suggestion');
    assertTrue(!isset($suggested[$confirmed]), 'an alt the owner confirmed is still marked as a guess');
    assertEquals([], MediaAlt::suggestedIds($db, []), 'an empty list still went to the database');
});

testBothDrivers('saving confirms a suggestion, even left word for word', function (string $driver) {
    [$storage] = mediaPaths();
    $db = installedSite(['en' => 'English'], $driver);
    $upload = new MediaUpload($db, $storage, new MediaEncoder());
    $id = $upload->store(imageFixture(tmpPath('kava_i_kolac.jpg'), 320, 240), 'kava_i_kolac.jpg')['id'];

    $before = MediaMeta::forPicture($db, $id);
    assertTrue($before['en']['suggested'] ?? false, 'the upload was not marked as a suggestion');

    // Saved UNCHANGED. Looking at a guess and pressing save is the confirmation, so the
    // badge has to go — otherwise the only way to silence it is to edit words the owner
    // already agrees with.
    MediaMeta::save($db, $id, 'en', $before['en']['alt'], '');

    $after = MediaMeta::forPicture($db, $id);
    assertEquals($before['en']['alt'], $after['en']['alt'], 'the words were changed by saving');
    assertTrue(!($after['en']['suggested'] ?? true), 'it still asks to be checked after being confirmed');
});

testBothDrivers('replacing refreshes a suggestion, and never overwrites a confirmed alt', function (string $driver) {
    [$storage] = mediaPaths();
    $db = installedSite(['en' => 'English'], $driver);
    $upload = new MediaUpload($db, $storage, new MediaEncoder());

    $id = $upload->store(imageFixture(tmpPath('prvi-motiv.jpg'), 320, 240), 'prvi-motiv.jpg')['id'];
    assertEquals('Prvi motiv', MediaMeta::forPicture($db, $id)['en']['alt'], 'the first suggestion');

    // Still only a guess, so new bytes may replace it with a better one. Different
    // dimensions mean different bytes, so this is a real replacement and not a duplicate.
    $upload->replace($id, imageFixture(tmpPath('drugi-motiv.jpg'), 300, 200), 'drugi-motiv.jpg');
    assertEquals('Drugi motiv', MediaMeta::forPicture($db, $id)['en']['alt'], 'the suggestion was not refreshed');

    // From here the words are the owner's.
    MediaMeta::save($db, $id, 'en', 'Naša radionica u sumrak', '');
    $upload->replace($id, imageFixture(tmpPath('treci-motiv.jpg'), 280, 180), 'treci-motiv.jpg');

    $after = MediaMeta::forPicture($db, $id);
    assertEquals('Naša radionica u sumrak', $after['en']['alt'], 'a replacement overwrote what the owner wrote');
    assertTrue(!($after['en']['suggested'] ?? true), 'a confirmed alt was turned back into a suggestion');
});

testBothDrivers('a device-named upload is given no alt at all', function (string $driver) {
    [$storage] = mediaPaths();
    $db = installedSite(['en' => 'English'], $driver);
    $upload = new MediaUpload($db, $storage, new MediaEncoder());

    $id = $upload->store(imageFixture(tmpPath('IMG_4032.jpg'), 320, 240), 'IMG_4032.jpg')['id'];

    // No row, rather than a row with an empty alt: an empty alt is the owner's decision
    // that the picture is decoration, and Boxlet has not made that decision for them.
    assertEquals([], $db->all('SELECT id FROM media_meta WHERE media_id = ' . $id), 'rows in media_meta');
});
