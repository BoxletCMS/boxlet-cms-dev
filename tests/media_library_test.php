<?php

use App\Core\Blocks;
use App\Modules\Media\MediaLibrary;
use App\Modules\Media\MediaMeta;
use App\Modules\Media\MediaUpload;
use App\Modules\Media\MediaEncoder;
use App\Modules\Media\MediaVariants;
use App\Modules\Media\MediaWriter;

// The picture library (SPEC §5.5): what there is, what uses it, and what happens when one
// goes.
//
// The case these exist for is deleting a picture that a page still shows. The check is a
// portable LIKE narrowed in SQL and confirmed by decoding the JSON in PHP, because neither
// engine can be relied on for JSON functions — and because LIKE alone cannot tell id 7
// from id 70, which is the difference between refusing a deletion and silently breaking a
// page.

function libraryFor(App\Core\Db $db): MediaLibrary
{
    [$storage, $public] = mediaPaths();

    return new MediaLibrary($db, Blocks::discover(dirname(__DIR__) . '/app/Blocks'), $storage, $public);
}

testBothDrivers('a picture used by a page cannot be deleted, and the refusal names the page', function (string $driver) {
    $db = adminSite($driver);
    $library = libraryFor($db);

    $db->query(
        'INSERT INTO media (filename, original_name, path, mime, size, width, height, hash, created_at, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        ['photo', 'photo.jpg', 'uploads/abc.jpg', 'image/jpeg', 100, 800, 600, 'hash-used', '2026-01-01 00:00:00', 'complete'],
    );
    $mediaId = (int) $db->lastInsertId();

    createPage($db, 'en', 'about', 'About us', true, [
        ['type' => 'hero', 'content' => ['heading' => 'Hello', 'image' => $mediaId]],
    ]);

    $used = $library->usedBy($mediaId);
    assertEquals(['About us'], array_values($used), 'the pages it says use the picture');

    $result = $library->delete($mediaId);
    assertTrue(!$result['deleted'], 'a picture in use was deleted');
    assertEquals(['About us'], array_values($result['used_by']), 'the refusal does not name the page');
    assertTrue($library->find($mediaId) !== null, 'the row went anyway');
});

// The case the PHP confirmation exists for. Measured: LIKE '%"image":7%' matches id 7 AND
// id 70 on both engines, so a check that trusted SQL alone would refuse to delete picture
// 7 because picture 70 is on a page — or worse, the other way round.
testBothDrivers('id 7 is not id 70', function (string $driver) {
    $db = adminSite($driver);
    $library = libraryFor($db);

    $ids = [];
    foreach ([7, 70] as $wanted) {
        $db->query(
            'INSERT INTO media (id, filename, original_name, path, mime, size, width, height, hash, created_at, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$wanted, 'p' . $wanted, 'p.jpg', 'uploads/' . $wanted . '.jpg', 'image/jpeg', 1, 8, 6, 'hash-' . $wanted, '2026-01-01 00:00:00', 'complete'],
        );
        $ids[] = $wanted;
    }

    // Only 70 is on a page.
    createPage($db, 'en', 'seventy', 'The seventy page', true, [
        ['type' => 'hero', 'content' => ['heading' => 'Seventy', 'image' => 70]],
    ]);

    assertEquals([], $library->usedBy(7), 'picture 7 was reported as used by a page that uses 70');
    assertEquals(['The seventy page'], array_values($library->usedBy(70)), 'picture 70');

    // So 7 deletes and 70 does not.
    assertTrue($library->delete(7)['deleted'], 'picture 7 could not be deleted');
    assertTrue(!$library->delete(70)['deleted'], 'picture 70 was deleted while in use');
});

testBothDrivers('deleting a picture removes its variants and its original', function (string $driver) {
    $db = adminSite($driver);
    [$storage, $public] = mediaPaths();
    $encoder = new MediaEncoder();
    $upload = new MediaUpload($db, $storage, $encoder);
    $variants = new MediaVariants($db, $encoder, new MediaWriter($encoder), $storage, $public);
    $library = new MediaLibrary($db, Blocks::discover(dirname(__DIR__) . '/app/Blocks'), $storage, $public);

    $id = $upload->store(imageFixture(tmpPath('to-delete.jpg'), 800, 600), 'To Delete.jpg')['id'];
    $variants->generate($id, null);

    $media = $db->one('SELECT * FROM media WHERE id = ?', [$id]);
    if ($media === null) {
        fail('the uploaded picture has no row');
    }
    $original = $storage . '/' . (string) $media['path'];
    $files = [];
    foreach (MediaVariants::of($media) as $preset => $variant) {
        foreach ($variant['formats'] as $format) {
            $files[] = $public . '/' . App\Modules\Media\MediaPresets::file($preset, $id, 'to-delete', $format);
        }
    }

    assertTrue(is_file($original), 'the original was never written');
    assertTrue(count($files) > 0 && is_file($files[0]), 'no variants were written');

    assertTrue($library->delete($id)['deleted'], 'the picture could not be deleted');

    assertTrue(!is_file($original), 'the original is still on disk');
    foreach ($files as $file) {
        assertTrue(!is_file($file), 'a variant is still on disk: ' . basename($file));
    }
    assertEquals(null, $library->find($id), 'the row is still there');
});

testBothDrivers('alt text and caption are kept per locale, and an empty alt is a choice', function (string $driver) {
    // No library here any more: what a picture MEANS moved to MediaMeta, which takes a Db
    // and nothing else. This test asserts rows, not files, so losing libraryFor()'s
    // incidental clearing of the media directories changes nothing it relies on.
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski'], $driver);

    $db->query(
        'INSERT INTO media (filename, original_name, path, mime, size, width, height, hash, created_at, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        ['photo', 'photo.jpg', 'uploads/m.jpg', 'image/jpeg', 1, 8, 6, 'hash-meta', '2026-01-01 00:00:00', 'complete'],
    );
    $id = (int) $db->lastInsertId();

    MediaMeta::save($db, $id, 'en', 'A studio at dusk', 'Our workshop');
    MediaMeta::save($db, $id, 'hr', 'Studio u sumrak', '');

    $meta = MediaMeta::forPicture($db, $id);
    assertEquals('A studio at dusk', $meta['en']['alt'], 'English alt');
    assertEquals('Studio u sumrak', $meta['hr']['alt'], 'Croatian alt');
    assertEquals('Our workshop', $meta['en']['caption'], 'English caption');

    // Saving again updates rather than inserting a second row: the table is unique on
    // (media_id, locale), so a second insert would be an error rather than an edit.
    MediaMeta::save($db, $id, 'en', '', 'Still our workshop');
    $meta = MediaMeta::forPicture($db, $id);
    assertEquals('', $meta['en']['alt'], 'an empty alt is stored, because decoration is a choice');
    assertEquals('Still our workshop', $meta['en']['caption'], 'the caption after an update');
    assertEquals(2, count($db->all('SELECT id FROM media_meta WHERE media_id = ' . $id)), 'rows in media_meta');
});

testBothDrivers('moving the focal point makes the cropped sizes again', function (string $driver) {
    $db = adminSite($driver);
    $library = libraryFor($db);

    $db->query(
        'INSERT INTO media (filename, original_name, path, mime, size, width, height, hash, created_at, status, variants_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        ['photo', 'photo.jpg', 'uploads/f.jpg', 'image/jpeg', 1, 800, 600, 'hash-focal', '2026-01-01 00:00:00', 'complete', '{"thumb":{"formats":["webp"],"width":200,"height":200}}'],
    );
    $id = (int) $db->lastInsertId();

    $library->setFocalPoint($id, 25, 80);

    $media = $library->find($id);
    if ($media === null) {
        fail('the picture vanished');
    }
    assertEquals(25, (int) $media['focal_x'], 'focal x');
    assertEquals(80, (int) $media['focal_y'], 'focal y');
    // The crops were taken around the old point, so they are no longer what they claim.
    assertEquals('incomplete', (string) $media['status'], 'the picture still calls itself complete');
    assertEquals(null, $media['variants_json'], 'the old variants are still recorded as current');

    // Out-of-range values are clamped rather than stored: a focal point off the picture
    // would push every crop against an edge.
    $library->setFocalPoint($id, -30, 500);
    $media = $library->find($id);
    assertEquals(0, (int) ($media['focal_x'] ?? -1), 'a negative x');
    assertEquals(100, (int) ($media['focal_y'] ?? -1), 'an x past the edge');
});

testBothDrivers('the library lists newest first and searches by name', function (string $driver) {
    $db = adminSite($driver);
    $library = libraryFor($db);

    foreach (['harbour', 'studio', 'workshop'] as $i => $name) {
        $db->query(
            'INSERT INTO media (filename, original_name, path, mime, size, width, height, hash, created_at, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$name, $name . '.jpg', 'uploads/' . $name . '.jpg', 'image/jpeg', 1, 8, 6, 'hash-' . $i, '2026-01-01 00:00:00', 'complete'],
        );
    }

    $all = $library->all();
    assertEquals(['workshop', 'studio', 'harbour'], array_column($all, 'filename'), 'newest first');
    assertEquals(['studio'], array_column($library->all('stud'), 'filename'), 'a partial name');
    assertEquals([], $library->all('nothing-like-this'), 'a search that matches nothing');
});

// A picture chosen as a section's own surface (PLAN.md D-024). It lives in style_json,
// not content_json, so the usage check has two sources — and both need the same
// LIKE-narrow plus PHP-decode, because style_json carries "image":7 exactly as
// content_json does and the LIKE cannot tell it from "image":70 either.

testBothDrivers('a picture used as a section surface blocks deletion too', function (string $driver) {
    $db = adminSite($driver);
    $library = libraryFor($db);

    $db->query(
        'INSERT INTO media (id, filename, original_name, path, mime, size, width, height, hash, created_at, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [7, 'seven', 'seven.jpg', 'uploads/seven.jpg', 'image/jpeg', 1, 8, 6, 'hash-surface-7', '2026-01-01 00:00:00', 'complete'],
    );
    $db->query(
        'INSERT INTO media (id, filename, original_name, path, mime, size, width, height, hash, created_at, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [70, 'seventy', 'seventy.jpg', 'uploads/seventy.jpg', 'image/jpeg', 1, 8, 6, 'hash-surface-70', '2026-01-01 00:00:00', 'complete'],
    );

    // A text block with no media field of its own, whose SECTION carries the picture.
    createPage($db, 'en', 'surface', 'The surface page', true, [
        ['type' => 'text', 'content' => ['body' => '<p>x</p>'], 'style' => ['surface' => 'image', 'image' => 70]],
    ]);

    assertEquals(['The surface page'], array_values($library->usedBy(70)), 'a surface picture is not found');
    assertEquals([], $library->usedBy(7), 'picture 7 was reported as used by a section using 70');

    $refused = $library->delete(70);
    assertTrue(!$refused['deleted'], 'a picture used as a surface was deleted');
    assertEquals(['The surface page'], array_values($refused['used_by']), 'the refusal does not name the page');

    assertTrue($library->delete(7)['deleted'], 'picture 7 could not be deleted');
});

testBothDrivers('an id naming a picture that is gone becomes null on save', function (string $driver) {
    $db = adminSite($driver);

    $db->query(
        'INSERT INTO media (id, filename, original_name, path, mime, size, width, height, hash, created_at, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [11, 'real', 'real.jpg', 'uploads/real.jpg', 'image/jpeg', 1, 8, 6, 'hash-real', '2026-01-01 00:00:00', 'complete'],
    );

    // 11 exists; 999 never did. Both are shaped like a media id.
    $pageId = createPage($db, 'en', 'surfaces', 'Surfaces', true, [
        ['type' => 'text', 'content' => ['body' => '<p>a</p>'], 'style' => ['surface' => 'image', 'image' => 11]],
        ['type' => 'text', 'content' => ['body' => '<p>b</p>'], 'style' => ['surface' => 'image', 'image' => 999]],
    ]);

    $stored = [];
    foreach (blocksWithStyle($db, $pageId) as $row) {
        $stored[] = $row['style']['image'] ?? null;
    }

    assertEquals([11, null], $stored, 'an id for a picture that does not exist was stored anyway');
});

testBothDrivers('deleting a picture does not leave a section pointing at it', function (string $driver) {
    $db = adminSite($driver);
    $library = libraryFor($db);

    $db->query(
        'INSERT INTO media (id, filename, original_name, path, mime, size, width, height, hash, created_at, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [21, 'used', 'used.jpg', 'uploads/used.jpg', 'image/jpeg', 1, 8, 6, 'hash-21', '2026-01-01 00:00:00', 'complete'],
    );

    // Used as a surface, so deletion is refused — which is what keeps the reference valid.
    createPage($db, 'en', 'held', 'Held', true, [
        ['type' => 'text', 'content' => ['body' => '<p>x</p>'], 'style' => ['surface' => 'image', 'image' => 21]],
    ]);

    assertTrue(!$library->delete(21)['deleted'], 'the picture was deleted while a section used it');
    assertTrue($library->find(21) !== null, 'the row went anyway');
});

// A picture inside a repeater item — a Columns block's column — is as much in use as one in
// a block's own field. Found while building the Media table's "Used on" (D-052).
testBothDrivers('a picture in a column of a Columns block is in use, and cannot be deleted', function (string $driver) {
    $db = adminSite($driver);
    $library = libraryFor($db);
    $db->query(
        'INSERT INTO media (filename, original_name, path, mime, size, width, height, hash, created_at, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        ['column-photo', 'column.jpg', 'uploads/col.jpg', 'image/jpeg', 100, 800, 600, 'hash-column', '2026-01-01 00:00:00', 'complete'],
    );
    $mediaId = (int) $db->lastInsertId();
    createPage($db, 'en', 'team', 'Team', true, [
        ['type' => 'columns', 'content' => ['heading' => 'Us', 'items' => [['image' => $mediaId, 'heading' => 'One']]]],
    ]);

    assertEquals(['Team'], array_values($library->usedBy($mediaId)), 'the page using it');
    assertTrue(!$library->delete($mediaId)['deleted'], 'deleted while a column shows it');
});
