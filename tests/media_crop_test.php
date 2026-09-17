<?php

use App\Modules\Media\MediaCrop;
use App\Modules\Media\MediaEncoder;
use App\Modules\Media\MediaMeta;
use App\Modules\Media\MediaVariants;
use App\Modules\Media\MediaWriter;

// CROPPING A PICTURE BY HAND (PLAN.md D-026).
//
// The browser sends a RECTANGLE, never an image, and the server cuts the original. So the
// two things worth asserting are the arithmetic that turns one coordinate space into the
// other, and the refusals that stand between a dragged box and a destroyed original.
//
// GEOMETRY, NEVER COLOUR. The orientation case is measured as sizes, for the reason
// media_encoding_test.php records: this machine's ImageMagick misreads the channel order
// of GD's synthetic JPEGs, so a colour assertion would test the environment.

/**
 * A rectangle as the dialog would send it, against a full variant of the given size.
 *
 * @return array{x: int, y: int, width: int, height: int, fullWidth: int, fullHeight: int}
 */
function sentRect(int $x, int $y, int $w, int $h, int $fullW, int $fullH): array
{
    return ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h, 'fullWidth' => $fullW, 'fullHeight' => $fullH];
}

test('a rectangle measured on the full variant is scaled to the original', function () {
    // The dialog showed a 1200-wide variant of a 2400-wide original, so every number
    // doubles. Getting this wrong crops the wrong half of the picture and nothing says so.
    $media = ['width' => 2400, 'height' => 1600, 'focal_x' => 50, 'focal_y' => 50];

    $rect = MediaCrop::rectangle($media, sentRect(100, 50, 600, 400, 1200, 800), 'free');

    assertEquals(['x' => 200, 'y' => 100, 'width' => 1200, 'height' => 800], $rect, 'scaled rectangle');
});

test('a crop is refused when it falls outside, is too small, or is the wrong shape', function () {
    $media = ['width' => 2400, 'height' => 1600, 'focal_x' => 50, 'focal_y' => 50];

    assertThrows(
        static fn () => MediaCrop::rectangle($media, sentRect(2300, 0, 400, 400, 2400, 1600), 'free'),
        'outside',
    );
    assertThrows(
        static fn () => MediaCrop::rectangle($media, sentRect(0, 0, 100, 100, 2400, 1600), 'free'),
        'smaller than',
    );
    // 1000x1000 is square, and hero is 16:9.
    assertThrows(
        static fn () => MediaCrop::rectangle($media, sentRect(0, 0, 1000, 1000, 2400, 1600), 'hero'),
        'does not match the shape',
    );
});

test('a fixed shape tolerates rounding but not a different shape', function () {
    $media = ['width' => 2400, 'height' => 1600, 'focal_x' => 50, 'focal_y' => 50];

    // 1599x900 is 16:9 to within a pixel, which is what dragging and rounding produce.
    $rect = MediaCrop::rectangle($media, sentRect(0, 0, 1599, 900, 2400, 1600), 'hero');
    assertEquals(1599, $rect['width'], 'a rectangle one pixel off 16:9 was refused');

    // 1500x900 is 5:3, which is a different shape and not a rounding error.
    assertThrows(
        static fn () => MediaCrop::rectangle($media, sentRect(0, 0, 1500, 900, 2400, 1600), 'hero'),
        'does not match the shape',
    );
});

test('the focal point carries into the crop, or returns to the centre', function () {
    $media = ['width' => 2400, 'height' => 1600, 'focal_x' => 50, 'focal_y' => 50];

    // The old point is pixel 1200,800. Inside a crop that starts at the origin it lands
    // three quarters across — surprising to look at, and correct: the crop kept the top
    // left, so what was the middle is now low and right of centre.
    $inside = MediaCrop::focalAfter($media, ['x' => 0, 'y' => 0, 'width' => 1600, 'height' => 1000]);
    assertEquals(['x' => 75, 'y' => 80], $inside, 'a point still in the picture');

    // A crop of the far corner cut the old point away. It returns to the centre rather
    // than clinging to an edge, which would push every later crop against that edge.
    $outside = MediaCrop::focalAfter($media, ['x' => 1800, 'y' => 1100, 'width' => 400, 'height' => 400]);
    assertEquals(['x' => 50, 'y' => 50], $outside, 'a point the crop cut away');
});

test('the orientation is applied before the cut, not after', function () {
    $encoder = new MediaEncoder();
    if ($encoder->driver() === null) {
        skip('no encoder on this machine, so nothing can be cut', 'images');
    }

    // 400x200 stored, tagged orientation 6, so it PRESENTS as 200x400. A crop taken before
    // the turn would be a crop of the sideways picture, and only ever for photographs
    // someone took in portrait — a fault that never appears on the machine it was written on.
    [$storage] = mediaPaths();
    $source = imageFixture(tmpPath('crop-orient.jpg'), 400, 200, 6);
    $upright = $encoder->inspect($source);
    assertEquals([200, 400], [$upright['width'], $upright['height']], 'the size a person sees');

    $media = ['width' => $upright['width'], 'height' => $upright['height'], 'focal_x' => 50, 'focal_y' => 50];
    $rect = MediaCrop::rectangle($media, sentRect(0, 0, 200, 200, 200, 400), 'thumb');
    $cut = MediaCrop::cut(new MediaWriter($encoder), $source, $storage, $rect, MediaEncoder::orientationOf($source), 'jpg');

    $size = getimagesize($cut);
    assertEquals([200, 200], [(int) ($size[0] ?? 0), (int) ($size[1] ?? 0)], 'the cut file');
    @unlink($cut);
});

testBothDrivers('saving as new keeps the original and copies what it means', function (string $driver) {
    $db = mediaAdminSite($driver);
    adminUpload('/admin/media', [['name' => 'tim-u-uredu.jpg', 'tmp_name' => imageFixture(tmpPath('tim-u-uredu.jpg'), 800, 600)]]);
    $id = (int) ($db->one('SELECT id FROM media')['id'] ?? 0);

    $before = MediaMeta::forPicture($db, $id);
    assertTrue($before['en']['suggested'] ?? false, 'the upload was not given a suggested alt to copy');

    $response = adminUpload('/admin/media/' . $id . '/crop', [], [
        'action' => 'new', 'ratio' => 'thumb',
        'x' => '0', 'y' => '0', 'w' => '400', 'h' => '400', 'full_w' => '800', 'full_h' => '600',
    ]);
    assertTrue($response->status === 302, 'the crop did not redirect');

    $rows = $db->all('SELECT id FROM media ORDER BY id');
    assertEquals(2, count($rows), 'the original was not kept alongside the crop');

    $newId = (int) ($rows[1]['id'] ?? 0);
    $copied = MediaMeta::forPicture($db, $newId);
    assertEquals($before['en']['alt'], $copied['en']['alt'] ?? '', 'the alt did not travel');
    // AND THE MARK TRAVELS UNCHANGED. Copying through save() would clear it, quietly
    // promoting a guess nobody has read into the owner's own words (D-025).
    assertTrue($copied['en']['suggested'] ?? false, 'a copied suggestion was recorded as confirmed');
});

testBothDrivers('replacing keeps the id, so every page using it shows the crop', function (string $driver) {
    $db = mediaAdminSite($driver);
    adminUpload('/admin/media', [['name' => 'luka.jpg', 'tmp_name' => imageFixture(tmpPath('luka.jpg'), 800, 600)]]);
    $id = (int) ($db->one('SELECT id FROM media')['id'] ?? 0);

    adminUpload('/admin/media/' . $id . '/crop', [], [
        'action' => 'replace', 'ratio' => 'thumb',
        'x' => '0', 'y' => '0', 'w' => '400', 'h' => '400', 'full_w' => '800', 'full_h' => '600',
    ]);

    assertEquals(1, count($db->all('SELECT id FROM media')), 'replacing added a picture instead of replacing one');

    $row = $db->one('SELECT width, height, status FROM media WHERE id = ?', [$id]);
    if ($row === null) {
        fail('the picture lost its id');
    }
    assertEquals([400, 400], [(int) $row['width'], (int) $row['height']], 'the row still describes the uncropped picture');
    // The variants are made again from the new bytes, so nothing serves the old crop.
    assertTrue(in_array((string) $row['status'], ['complete', 'incomplete'], true), 'status');
    assertTrue(MediaVariants::of(['variants_json' => $db->one('SELECT variants_json FROM media WHERE id = ?', [$id])['variants_json'] ?? null]) !== [],
        'no variants were made from the cropped bytes');
});

testBothDrivers('a crop that is refused changes nothing', function (string $driver) {
    $db = mediaAdminSite($driver);
    adminUpload('/admin/media', [['name' => 'netaknuta.jpg', 'tmp_name' => imageFixture(tmpPath('netaknuta.jpg'), 800, 600)]]);
    $id = (int) ($db->one('SELECT id FROM media')['id'] ?? 0);
    $before = $db->one('SELECT width, height, hash FROM media WHERE id = ?', [$id]);

    // Far too small: below MediaCrop::MIN_SIDE once scaled.
    adminUpload('/admin/media/' . $id . '/crop', [], [
        'action' => 'replace', 'ratio' => 'free',
        'x' => '0', 'y' => '0', 'w' => '50', 'h' => '50', 'full_w' => '800', 'full_h' => '600',
    ]);

    assertEquals($before, $db->one('SELECT width, height, hash FROM media WHERE id = ?', [$id]), 'the picture changed anyway');
    assertEquals(1, count($db->all('SELECT id FROM media')), 'a refused crop still added a picture');
});

testBothDrivers('cropping without a CSRF token is refused', function (string $driver) {
    $db = mediaAdminSite($driver);
    adminUpload('/admin/media', [['name' => 'csrf.jpg', 'tmp_name' => imageFixture(tmpPath('csrf.jpg'), 800, 600)]]);
    $id = (int) ($db->one('SELECT id FROM media')['id'] ?? 0);

    // No _csrf in the body: dispatch() is used directly, because adminUpload() adds one.
    $response = dispatch('/admin/media/' . $id . '/crop', null, 'POST', [
        'action' => 'replace', 'ratio' => 'free',
        'x' => '0', 'y' => '0', 'w' => '400', 'h' => '400', 'full_w' => '800', 'full_h' => '600',
    ], '203.0.113.10', mediaAdminContainer());

    assertEquals(403, $response->status, 'a crop without a token was accepted');
    assertEquals(1, count($db->all('SELECT id FROM media')), 'it cropped anyway');
});
