<?php

use App\Modules\Media\MediaReference;

// THE MEDIA BROWSER A PICTURE FIELD OPENS (PLAN.md D-145): the library a page at a time,
// searched by name or description, and a picture uploaded from inside the editor — cut on
// the way in when a rectangle comes with it, and only the cut kept.
//
// Sizes, never colours, for the reason media_crop_test.php records.

/**
 * Posts one file to the browser's upload, the shape a single file input sends.
 *
 * @param array<string, string> $body
 */
function pickUpload(string $name, string $file, array $body = []): App\Core\Response
{
    return adminUpload('/admin/media/pick', [['name' => $name, 'tmp_name' => $file]], $body, 'file');
}

/**
 * The rectangle fields the crop step sends, measured on the file as the browser shows it.
 *
 * @return array<string, string>
 */
function pickRect(int $x, int $y, int $w, int $h, int $fullW, int $fullH, string $ratio = 'free'): array
{
    return [
        'crop' => '1', 'x' => (string) $x, 'y' => (string) $y, 'w' => (string) $w, 'h' => (string) $h,
        'full_w' => (string) $fullW, 'full_h' => (string) $fullH, 'ratio' => $ratio,
    ];
}

testBothDrivers('a picture cropped on upload is stored as the cut, and only the cut', function (string $driver) {
    $db = mediaAdminSite($driver);

    $response = pickUpload('Harbour.jpg', imageFixture(tmpPath('pick-crop.jpg'), 800, 600), pickRect(100, 50, 400, 300, 800, 600));
    assertEquals(200, $response->status, 'status');

    $rows = $db->all('SELECT * FROM media');
    assertEquals(1, count($rows), 'pictures stored');
    assertEquals([400, 300], [(int) $rows[0]['width'], (int) $rows[0]['height']], 'the stored picture is the cut');
    assertEquals('harbour', (string) $rows[0]['filename'], 'named from what the file was called');

    // The answer is the library's own card, which is what the browser chooses by.
    assertContains('data-pick="' . $rows[0]['id'] . '"', $response->body, 'the card that chooses it');
    assertContains('data-pick-complete="1"', $response->body, 'its sizes, made in the same request');
    assertTrue(!str_contains($response->body, '<!doctype'), 'a fragment, not a screen');
});

testBothDrivers('the rectangle is in the picture as it stands upright, as the browser shows it', function (string $driver) {
    $db = mediaAdminSite($driver);

    // Stored 600×400 and turned a quarter by its EXIF, so it is 400×600 on screen. A box in
    // the lower half — y 300 to 600 — exists only in the upright picture: measured in the
    // stored one, it would fall outside and be refused.
    $file = imageFixture(tmpPath('pick-portrait.jpg'), 600, 400, 6);
    $response = pickUpload('portrait.jpg', $file, pickRect(0, 300, 400, 300, 400, 600));
    assertEquals(200, $response->status, 'status: ' . strip_tags($response->body));

    $row = $db->one('SELECT width, height FROM media') ?? fail('nothing was stored');
    assertEquals([400, 300], [(int) $row['width'], (int) $row['height']], 'the cut of an upright portrait');
});

testBothDrivers('a picture uploaded without crop is stored as it came', function (string $driver) {
    $db = mediaAdminSite($driver);

    assertEquals(200, pickUpload('whole.jpg', imageFixture(tmpPath('pick-whole.jpg'), 640, 480))->status, 'status');

    $row = $db->one('SELECT width, height FROM media') ?? fail('nothing was stored');
    assertEquals([640, 480], [(int) $row['width'], (int) $row['height']], 'the whole picture');
});

testBothDrivers('a crop the wrong shape is refused in words, and nothing is stored', function (string $driver) {
    $db = mediaAdminSite($driver);

    // 400×400 is square; 16:9 was pressed.
    $response = pickUpload('square.jpg', imageFixture(tmpPath('pick-wrong.jpg'), 800, 600), pickRect(0, 0, 400, 400, 800, 600, 'hero'));
    assertEquals(422, $response->status, 'status');
    assertContains('does not match the shape', $response->body, 'the reason');
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM media')['n'] ?? -1), 'pictures stored');
});

testBothDrivers('a picture already in the library is chosen, not stored twice', function (string $driver) {
    $db = mediaAdminSite($driver);
    $file = imageFixture(tmpPath('pick-twice.jpg'), 320, 240);

    pickUpload('once.jpg', $file);
    $id = (int) ($db->one('SELECT id FROM media')['id'] ?? 0);
    $again = pickUpload('twice.jpg', $file);

    assertEquals(200, $again->status, 'status');
    assertContains('data-pick="' . $id . '"', $again->body, 'the picture already there');
    assertEquals(1, (int) ($db->one('SELECT COUNT(*) AS n FROM media')['n'] ?? -1), 'pictures stored');
});

testBothDrivers('a document is refused by a picture field, not filed as a download', function (string $driver) {
    $db = mediaAdminSite($driver);

    $response = pickUpload('Price list.pdf', pdfFixture(tmpPath('pick-price.pdf')));
    assertEquals(422, $response->status, 'status');
    assertContains('That is not a picture', $response->body, 'the reason');
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM media')['n'] ?? -1), 'rows stored');
});

testBothDrivers('the browser shows the library a page at a time, with a way to the next', function (string $driver) {
    $db = mediaAdminSite($driver);
    for ($i = 1; $i <= 49; $i++) {
        storedPicture($db, 'paged-' . $i, []);
    }

    $first = mediaAdminGet('/admin/media/pick')->body;
    assertEquals(48, substr_count($first, 'data-pick="'), 'cards on the first page');
    assertContains('data-pick-more="2"', $first, 'the way to the next page');
    // Newest first: the last stored is on the first page, the first stored is not.
    assertContains('paged-49', $first, 'the newest picture');
    assertTrue(!str_contains($first, 'data-pick-name="paged-1"'), 'the oldest picture is on the first page');

    $second = mediaAdminGet('/admin/media/pick?page=2')->body;
    assertEquals(1, substr_count($second, 'data-pick="'), 'cards on the second page');
    assertContains('data-pick-name="paged-1"', $second, 'the oldest picture');
    assertTrue(!str_contains($second, 'data-pick-more'), 'a way past the last page');
});

testBothDrivers('the search finds a picture by its description, in any language', function (string $driver) {
    $db = mediaAdminSite($driver);
    $harbour = storedPicture($db, 'img-4471', []);
    storedPicture($db, 'img-4472', []);
    $db->query('INSERT INTO media_meta (media_id, locale, alt, caption) VALUES (?, ?, ?, ?)', [$harbour, 'hr', 'Luka u zoru', '']);

    $found = mediaAdminGet('/admin/media/pick?q=zoru')->body;
    assertContains('data-pick="' . $harbour . '"', $found, 'the described picture');
    assertEquals(1, substr_count($found, 'data-pick="'), 'pictures found');
    // The library screen searches the same way: one search, in MediaLibrary.
    assertContains('img-4471', mediaAdminGet('/admin/media?q=zoru')->body, 'the library screen');
});

testBothDrivers('the first opening fetches the dialog around the cards', function (string $driver) {
    $db = mediaAdminSite($driver);
    $id = storedPicture($db, 'in-the-dialog', []);

    $dialog = mediaAdminGet('/admin/media/pick?dialog=1')->body;
    assertContains('<dialog', $dialog, 'the dialog');
    assertContains('data-browser-search', $dialog, 'the search');
    assertContains('data-browser-file', $dialog, 'the upload');
    assertContains('data-pick="' . $id . '"', $dialog, 'the first page of cards');
    // Its five shapes are the library crop's, named as the server checks them.
    foreach (['free', 'hero', 'card', 'wide', 'thumb'] as $shape) {
        assertContains('data-crop-name="' . $shape . '"', $dialog, 'the ' . $shape . ' shape');
    }
    // Every later page asks for the cards alone.
    assertTrue(!str_contains(mediaAdminGet('/admin/media/pick')->body, '<dialog'), 'a later page carries the dialog');
});

testBothDrivers('finishing a picture from the browser answers with its card', function (string $driver) {
    $db = mediaAdminSite($driver);
    pickUpload('finish.jpg', imageFixture(tmpPath('pick-finish.jpg'), 320, 240));
    $id = (int) ($db->one('SELECT id FROM media')['id'] ?? 0);

    $response = adminUpload('/admin/media/pick/' . $id . '/finish', [], [], 'file');
    assertEquals(200, $response->status, 'status');
    assertContains('data-pick="' . $id . '"', $response->body, 'the card');
    assertEquals(404, adminUpload('/admin/media/pick/' . ($id + 99) . '/finish', [], [], 'file')->status, 'a picture that is not there');
});

test('a block field opens the crop with the shape its block draws the picture in', function () {
    installedSite();

    // Fixed by the block.
    assertContains('data-picker-crop="hero"', MediaReference::pickerAttributes('block.hero.image'), 'the hero');
    assertContains('data-picker-crop="thumb"', MediaReference::pickerAttributes('block.quote.portrait'), 'a quote\'s portrait');
    // Chosen by one of the block's own fields, read when the crop opens.
    $picture = MediaReference::pickerAttributes('block.picture.image');
    assertContains('data-picker-crop-field="shape"', $picture, 'the picture block reads its shape field');
    assertContains('&quot;wide&quot;:&quot;hero&quot;', $picture, 'wide is drawn 16:9');
    assertContains('data-picker-crop-field="image_shape"', MediaReference::pickerAttributes('block.columns.items.image'), 'a column\'s picture');
    // Anything else opens with Free: no hint at all.
    assertTrue(!str_contains(MediaReference::pickerAttributes(), 'data-picker-crop'), 'a picture that is not a block\'s');
    assertTrue(!str_contains(MediaReference::pickerAttributes('block.image_text.image'), 'data-picker-crop'), 'a block with no fixed shape');
});
