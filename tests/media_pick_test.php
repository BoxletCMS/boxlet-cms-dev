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

// AN EMBED BLOCK'S COVER, TAKEN FROM THE VIDEO BY THE SERVER (PLAN.md D-147). The network is
// a closure here: each test says what YouTube and Vimeo answer, and nothing leaves this machine.

/**
 * A JPEG of the given size, as bytes.
 */
function posterBytes(int $width, int $height): string
{
    return (string) file_get_contents(imageFixture(tmpPath('poster-' . $width . 'x' . $height . '.jpg'), $width, $height));
}

/**
 * The browser's cover request, answered by a network of the test's own.
 *
 * @param array<string, string> $answers URL => body; anything else is unreachable
 */
function posterRequest(string $url, array $answers): App\Core\Response
{
    $asked = static fn (string $address): ?string => $answers[$address] ?? null;

    return dispatch('/admin/media/pick/poster', null, 'POST', ['_csrf' => (new App\Core\Session())->csrfToken(), 'url' => $url], '203.0.113.10',
        static function (App\Core\Container $container) use ($asked): void {
            mediaAdminContainer()($container);
            $container->set('embed_poster', static fn () => new App\Modules\Media\EmbedPoster($asked));
        });
}

testBothDrivers('a YouTube video\'s own still becomes its cover, named after the video', function (string $driver) {
    $db = mediaAdminSite($driver);
    $response = posterRequest('https://youtu.be/aqz-KE-bpKQ', [
        // Slashes in a title are not a path: measured, "…/San Sebastian/Agosto 2018" was
        // stored as "agosto-2018".
        'https://www.youtube.com/oembed?format=json&url=' . rawurlencode('https://www.youtube.com/watch?v=aqz-KE-bpKQ') => '{"title":"Spain, live/San Sebastian"}',
        'https://i.ytimg.com/vi/aqz-KE-bpKQ/maxresdefault.jpg' => posterBytes(1280, 720),
    ]);
    assertEquals(200, $response->status, 'status: ' . strip_tags($response->body));

    $row = $db->one('SELECT * FROM media') ?? fail('nothing was stored');
    assertEquals('spain-live-san-sebastian', (string) $row['filename'], 'named after the video');
    assertEquals([1280, 720], [(int) $row['width'], (int) $row['height']], 'the largest still');
    assertContains('data-pick="' . $row['id'] . '"', $response->body, 'the card that chooses it');
});

testBothDrivers('a video with no large still falls back to the smaller one, never to YouTube\'s grey placeholder', function (string $driver) {
    $db = mediaAdminSite($driver);
    // YouTube answers a missing maxresdefault with a 120-pixel grey picture, not an error.
    posterRequest('https://www.youtube.com/watch?v=aqz-KE-bpKQ', [
        'https://i.ytimg.com/vi/aqz-KE-bpKQ/maxresdefault.jpg' => posterBytes(120, 90),
        'https://i.ytimg.com/vi/aqz-KE-bpKQ/hqdefault.jpg' => posterBytes(480, 360),
    ]);
    $row = $db->one('SELECT width, filename FROM media') ?? fail('nothing was stored');
    assertEquals(480, (int) $row['width'], 'the still that is one');
    assertEquals('youtube-aqz-ke-bpkq', (string) $row['filename'], 'named by its id when the title is unknown');
});

testBothDrivers('a Vimeo still is taken only from Vimeo\'s own picture host', function (string $driver) {
    $db = mediaAdminSite($driver);
    $oembed = 'https://vimeo.com/api/oembed.json?width=1280&url=' . rawurlencode('https://vimeo.com/148751763');

    $elsewhere = posterRequest('https://vimeo.com/148751763', [
        $oembed => '{"title":"Kayak","thumbnail_url":"https://evil.example/x.jpg"}',
        'https://evil.example/x.jpg' => posterBytes(640, 360),
    ]);
    assertEquals(422, $elsewhere->status, 'a still named on another host was fetched');

    $own = posterRequest('https://vimeo.com/148751763', [
        $oembed => '{"title":"Kayak","thumbnail_url":"https://i.vimeocdn.com/video/1-d_1280"}',
        'https://i.vimeocdn.com/video/1-d_1280' => posterBytes(1280, 720),
    ]);
    assertEquals(200, $own->status, 'status: ' . strip_tags($own->body));
    assertEquals('kayak', (string) ($db->one('SELECT filename FROM media')['filename'] ?? ''), 'the Vimeo still');
});

testBothDrivers('an address that is not a video, or a video that cannot be reached, is refused in words', function (string $driver) {
    $db = mediaAdminSite($driver);

    $map = posterRequest('https://www.google.com/maps/@45.8131,15.9775,16z', []);
    assertEquals(422, $map->status, 'a map');
    assertContains('not a YouTube or Vimeo video', $map->body, 'the reason');

    $gone = posterRequest('https://youtu.be/aqz-KE-bpKQ', []);
    assertEquals(422, $gone->status, 'an unreachable video');
    assertContains('could not be fetched', $gone->body, 'the reason');
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM media')['n'] ?? -1), 'pictures stored');
});

test('an Embed block\'s cover field can take its picture from the video', function () {
    installedSite();
    assertContains('data-poster-url="/admin/media/pick/poster"', MediaReference::pickerAttributes('block.embed.poster'), 'the embed cover');
    assertTrue(!str_contains(MediaReference::pickerAttributes('block.picture.image'), 'data-poster-url'), 'another picture field');
});
