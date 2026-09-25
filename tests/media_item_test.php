<?php

// One picture through the admin (SPEC §5.5, PLAN.md step 4b): what it means, what stays
// in frame when it is cropped, putting different bytes behind it, and removing it.
//
// Split from media_admin_test.php, which had grown past the 300-line rule. The helpers
// live there and are shared: run.php requires every test file before running any test, so
// mediaAdminSite(), adminUpload() and mediaAdminGet() are defined by the time these run.

testBothDrivers('a picture a page uses cannot be deleted through the admin', function (string $driver) {
    $db = mediaAdminSite($driver);
    adminUpload('/admin/media', [['name' => 'in-use.jpg', 'tmp_name' => imageFixture(tmpPath('in-use.jpg'), 320, 240)]]);
    $id = (int) ($db->one('SELECT id FROM media')['id'] ?? 0);

    createPage($db, 'en', 'about', 'About us', true, [
        ['type' => 'hero', 'content' => ['heading' => 'Hello', 'image' => $id]],
    ]);

    $response = adminUpload('/admin/media/' . $id . '/delete', []);
    assertRedirectedTo('/admin/media/' . $id, $response);
    assertTrue($db->one('SELECT id FROM media WHERE id = ?', [$id]) !== null, 'the picture was deleted anyway');

    $flash = $_SESSION['flash'] ?? '';
    assertContains('About us', is_string($flash) ? $flash : '', 'the refusal does not name the page');
});

testBothDrivers('a picture nothing uses is deleted, with its files', function (string $driver) {
    $db = mediaAdminSite($driver);
    adminUpload('/admin/media', [['name' => 'spare.jpg', 'tmp_name' => imageFixture(tmpPath('spare.jpg'), 320, 240)]]);
    $id = (int) ($db->one('SELECT id FROM media')['id'] ?? 0);

    $response = adminUpload('/admin/media/' . $id . '/delete', []);
    assertRedirectedTo('/admin/media', $response);
    assertEquals([], $db->all('SELECT id FROM media'), 'the row is still there');
    assertEquals([], glob(tmpPath('admin-media-public') . '/m/thumb/*') ?: [], 'the generated files stayed behind');
});

// A failed encode can leave bytes on disk that variants_json never mentions, because a
// format is only recorded once it has been written. Deleting the picture removed exactly
// what the record listed, so a half-written file stayed for ever with nothing left to say
// whose it was — seen on CI as a stray .avif surviving the delete.
testBothDrivers('deleting a picture removes files it never finished writing', function (string $driver) {
    $db = mediaAdminSite($driver);
    adminUpload('/admin/media', [['name' => 'orphan.jpg', 'tmp_name' => imageFixture(tmpPath('orphan.jpg'), 320, 240)]]);
    $row = $db->one('SELECT id, filename FROM media');
    $id = (int) ($row['id'] ?? 0);
    $filename = (string) ($row['filename'] ?? '');

    // What a half-finished encode leaves: a file wearing the picture's name that no record
    // mentions. Written by hand because an encoder that fails on demand cannot be built —
    // MediaEncoder and MediaWriter are both final.
    $stray = tmpPath('admin-media-public') . '/m/thumb/' . $id . '-' . $filename . '.avif';
    file_put_contents($stray, 'not a real avif, but it is on disk');
    assertTrue(is_file($stray), 'the stray file was not created, so this would prove nothing');

    adminUpload('/admin/media/' . $id . '/delete', []);

    assertEquals([], $db->all('SELECT id FROM media'), 'the row is still there');
    assertTrue(!is_file($stray), 'a file the record never listed survived the delete');
    assertEquals([], glob(tmpPath('admin-media-public') . '/m/thumb/*') ?: [], 'the generated files stayed behind');
});

testBothDrivers('alt text is kept per locale, and an empty alt is stored as a choice', function (string $driver) {
    $db = mediaAdminSite($driver);
    adminUpload('/admin/media', [['name' => 'meaning.jpg', 'tmp_name' => imageFixture(tmpPath('meaning.jpg'), 320, 240)]]);
    $id = (int) ($db->one('SELECT id FROM media')['id'] ?? 0);

    $response = adminUpload('/admin/media/' . $id, [], [
        'alt_en' => 'A harbour at dawn',
        'caption_en' => 'Split, 2026',
        // Deliberately empty: this picture is decoration in Croatian. An empty alt is a
        // decision — it tells a screen reader to pass over the picture — so it has to be
        // stored rather than treated as a field nobody filled in.
        'alt_hr' => '',
        'caption_hr' => '',
    ]);
    assertRedirectedTo('/admin/media/' . $id, $response);

    // Keyed by locale rather than taken in row order: which row comes back first is the
    // database's business, and an assertion that depends on it reports the wrong failure.
    $alt = [];
    foreach ($db->all('SELECT locale, alt FROM media_meta WHERE media_id = ?', [$id]) as $row) {
        $alt[(string) $row['locale']] = (string) $row['alt'];
    }

    assertEquals(2, count($alt), 'a row per locale');
    assertEquals('A harbour at dawn', $alt['en'] ?? null, 'the English alt');
    assertEquals('', $alt['hr'] ?? null, 'the empty Croatian alt was not stored');

    assertContains('A harbour at dawn', mediaAdminGet('/admin/media/' . $id)->body, 'the screen does not show what was saved');
});

/*
 * THE FOCAL POINT IS BACK (PLAN.md D-121), and moving it makes the cut sizes again safely.
 *
 * D-038 took it away because the owner had not seen what it was for; the cover hero, cut to
 * a phone's shape, is what it is for. The first version emptied the picture's sizes and
 * made them again under the same addresses. This one goes through the remake: the files
 * are replaced whole, the uncropped sizes are left alone, and the revision — part of every
 * address — goes up, so no browser keeps the old cut.
 */
testBothDrivers('a picture\'s page offers the focal point, and moving it makes the cut sizes again', function (string $driver) {
    $db = mediaAdminSite($driver);
    adminUpload('/admin/media', [['name' => 'plain.jpg', 'tmp_name' => imageFixture(tmpPath('plain.jpg'), 600, 400)]]);
    $id = (int) ($db->one('SELECT id FROM media')['id'] ?? 0);
    $before = $db->one('SELECT revision, variants_json FROM media WHERE id = ?', [$id]) ?? fail('no row');

    $body = mediaAdminGet('/admin/media/' . $id)->body;
    assertContains('data-focal-form', $body, 'the page offers no focal point');
    assertContains('id="focal-x" name="x" min="0" max="100" value="50"', $body, 'the point is not shown where it is');

    $response = adminUpload('/admin/media/' . $id . '/focal', [], ['x' => '20', 'y' => '80']);
    assertEquals(302, $response->status, 'the move was not accepted');

    $after = $db->one('SELECT focal_x, focal_y, remake, revision, variants_json FROM media WHERE id = ?', [$id]) ?? fail('no row');
    assertEquals([20, 80], [(int) $after['focal_x'], (int) $after['focal_y']], 'the point that was stored');
    assertEquals(null, $after['remake'], 'the cut sizes were not all made again');
    assertEquals((int) $before['revision'] + 1, (int) $after['revision'], 'the addresses did not change, so a browser keeps the old cut');
    // Made again, not emptied: every size the picture had, it still has.
    assertEquals(
        array_keys((array) json_decode((string) $before['variants_json'], true)),
        array_keys((array) json_decode((string) $after['variants_json'], true)),
        'the picture lost sizes',
    );
    assertEquals(1, (int) ($db->one("SELECT COUNT(*) AS n FROM activity WHERE kind = 'media' AND action = 'focal'")['n'] ?? 0), 'the move is not in the log');

    // Out of range is held to the picture, not refused.
    adminUpload('/admin/media/' . $id . '/focal', [], ['x' => '140', 'y' => '-5']);
    $held = $db->one('SELECT focal_x, focal_y FROM media WHERE id = ?', [$id]) ?? fail('no row');
    assertEquals([100, 0], [(int) $held['focal_x'], (int) $held['focal_y']], 'a point off the picture');
});

testBothDrivers('replacing a picture keeps its id, so pages using it need no editing', function (string $driver) {
    $db = mediaAdminSite($driver);
    adminUpload('/admin/media', [['name' => 'before.jpg', 'tmp_name' => imageFixture(tmpPath('before.jpg'), 400, 300)]]);
    $id = (int) ($db->one('SELECT id FROM media')['id'] ?? 0);

    $pageId = createPage($db, 'en', 'home-2', 'Home', true, [
        ['type' => 'hero', 'content' => ['heading' => 'Hello', 'image' => $id]],
    ]);

    $response = adminUpload('/admin/media/' . $id . '/replace', [
        ['name' => 'after.jpg', 'tmp_name' => imageFixture(tmpPath('after.jpg'), 500, 250)],
    ], [], 'file');
    assertRedirectedTo('/admin/media/' . $id, $response);

    $row = $db->one('SELECT * FROM media WHERE id = ?', [$id]) ?? fail('the row went');
    assertEquals(500, (int) $row['width'], 'the new bytes were not adopted');
    // The library name is what the owner called this picture, not a property of the bytes.
    assertEquals('before', $row['filename'], 'the library name changed under the owner');
    assertEquals(1, count($db->all('SELECT id FROM media')), 'replacing made a second picture');

    $content = json_decode((string) ($db->one(
        'SELECT content_json FROM page_blocks WHERE page_id = ?',
        [$pageId],
    )['content_json'] ?? ''), true);
    assertEquals($id, is_array($content) ? $content['image'] : null, 'the page no longer points at the picture');
});
