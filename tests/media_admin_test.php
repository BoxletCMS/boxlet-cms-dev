<?php

use App\Core\Container;
use App\Core\Response;
use App\Core\Session;
use App\Modules\Media\MediaLibrary;
use App\Modules\Media\MediaVariants;
use App\Support\Bytes;

// The picture library through the admin (SPEC §5.5, PLAN.md step 4b): the screen that
// lists pictures, and the form that uploads them. One picture on its own — its meaning,
// its focal point, replacing and deleting it — is media_item_test.php, which shares the
// helpers below.
//
// These dispatch real requests, so they cover what the unit tests cannot reach: the
// routes, the CSRF check, the shape PHP builds in $_FILES for a multiple upload, and the
// redirect-with-a-message every action ends in.
//
// Variants are written to a directory of the test's own. Without that, generating them
// during a test would write real files into the project's public/m/ — the repository,
// not a fixture.

/**
 * A container whose media services write inside tests/tmp.
 */
function mediaAdminContainer(): Closure
{
    $public = tmpPath('admin-media-public');
    if (!is_dir($public)) {
        mkdir($public, 0700, true);
    }
    $storage = tmpPath('storage');

    return static function (Container $container) use ($public, $storage): void {
        $container->set('media_variants', static fn (Container $c) => new MediaVariants(
            $c->get('db'),
            $c->get('media_encoder'),
            $c->get('media_writer'),
            $storage,
            $public,
        ));
        $container->set('media_library', static fn (Container $c) => new MediaLibrary(
            $c->get('db'),
            $c->get('blocks'),
            $storage,
            $public,
        ));
    };
}

/**
 * An admin site whose generated pictures start from nothing.
 *
 * The variant directory outlives a single test, because it is a directory and not the
 * database: without this, a test asserting what was generated — or what was removed —
 * would be reading the previous test's files and passing or failing for its reasons.
 */
function mediaAdminSite(string $driver): \App\Core\Db
{
    $db = adminSite($driver);
    removeTree(tmpPath('admin-media-public'));
    mkdir(tmpPath('admin-media-public'), 0700, true);

    return $db;
}

/**
 * Dispatches an admin POST carrying files, the way a multipart form does.
 *
 * $_FILES is not saved and restored by dispatch(), so it is set and cleared here. PHP
 * hands a multiple upload over as arrays inside ONE entry — name[0], tmp_name[0] — which
 * is the shape the controller has to flatten, so the fixture builds exactly that.
 *
 * @param list<array{name: string, tmp_name: string, error?: int}> $files
 * @param array<string, mixed> $body
 */
function adminUpload(string $path, array $files, array $body = [], string $field = 'files'): Response
{
    $entry = ['name' => [], 'tmp_name' => [], 'error' => [], 'type' => [], 'size' => []];
    foreach ($files as $file) {
        $entry['name'][] = $file['name'];
        $entry['tmp_name'][] = $file['tmp_name'];
        $entry['error'][] = $file['error'] ?? UPLOAD_ERR_OK;
        $entry['type'][] = '';
        $entry['size'][] = is_file($file['tmp_name']) ? (int) filesize($file['tmp_name']) : 0;
    }
    // A single-file input is not an array of anything; "replace" uses that shape.
    if ($field !== 'files') {
        $entry = ['name' => $entry['name'][0] ?? '', 'tmp_name' => $entry['tmp_name'][0] ?? '',
            'error' => $entry['error'][0] ?? UPLOAD_ERR_NO_FILE, 'type' => '', 'size' => $entry['size'][0] ?? 0];
    }
    $_FILES = [$field => $entry];

    try {
        return dispatch($path, null, 'POST', ['_csrf' => (new Session())->csrfToken()] + $body, '203.0.113.10', mediaAdminContainer());
    } finally {
        $_FILES = [];
    }
}

function mediaAdminGet(string $path): Response
{
    return dispatch($path, null, 'GET', [], '203.0.113.10', mediaAdminContainer());
}

testBothDrivers('uploading through the admin stores the picture and makes its thumbnail', function (string $driver) {
    $db = mediaAdminSite($driver);
    $source = imageFixture(tmpPath('upload-one.jpg'), 400, 300);

    $response = adminUpload('/admin/media', [['name' => 'Seaside Photo.jpg', 'tmp_name' => $source]]);
    assertRedirectedTo('/admin/media', $response);

    $row = $db->one('SELECT * FROM media') ?? fail('nothing was stored');
    // The stored name is generated from what the file was called, never taken from it.
    assertEquals('seaside-photo', $row['filename'], 'library name');
    assertEquals('Seaside Photo.jpg', $row['original_name'], 'original name');
    assertEquals(400, (int) $row['width'], 'width read from the file');

    // The thumbnail is what the library screen draws, so it has to exist by the time the
    // screen is next rendered.
    $thumbs = glob(tmpPath('admin-media-public') . '/m/thumb/*') ?: [];
    assertTrue($thumbs !== [], 'no thumbnail was generated');

    $body = mediaAdminGet('/admin/media')->body;
    assertContains('seaside-photo', $body, 'the library screen');
    assertContains('/m/thumb/', $body, 'the library screen shows a thumbnail');
});

testBothDrivers('several files arrive as one entry of arrays, and all of them are stored', function (string $driver) {
    $db = mediaAdminSite($driver);
    // Different sizes so the bytes differ: identical bytes are deduplicated by hash, which
    // would make this pass for the wrong reason.
    $first = imageFixture(tmpPath('multi-a.jpg'), 400, 300);
    $second = imageFixture(tmpPath('multi-b.jpg'), 360, 240);

    adminUpload('/admin/media', [
        ['name' => 'one.jpg', 'tmp_name' => $first],
        ['name' => 'two.jpg', 'tmp_name' => $second],
    ]);

    assertEquals(2, count($db->all('SELECT id FROM media')), 'only the first file of a multiple upload was read');
});

testBothDrivers('a file that is not a picture is refused, and the refusal names it', function (string $driver) {
    $db = mediaAdminSite($driver);
    $notAPicture = tmpPath('notes.jpg');
    file_put_contents($notAPicture, "This is plain text wearing a .jpg extension.\n");

    adminUpload('/admin/media', [['name' => 'notes.jpg', 'tmp_name' => $notAPicture]]);

    assertEquals([], $db->all('SELECT id FROM media'), 'a text file was stored as a picture');
    $flash = $_SESSION['flash'] ?? '';
    assertContains('notes.jpg', is_string($flash) ? $flash : '', 'the refusal does not name the file');
});

testBothDrivers('the same bytes twice are stored once and said so', function (string $driver) {
    $db = mediaAdminSite($driver);
    $source = imageFixture(tmpPath('twice.jpg'), 320, 240);

    adminUpload('/admin/media', [['name' => 'first.jpg', 'tmp_name' => $source]]);
    // place() copies rather than moves for a file that is not a real upload, so the
    // fixture is still there for the second attempt.
    adminUpload('/admin/media', [['name' => 'again.jpg', 'tmp_name' => $source]]);

    assertEquals(1, count($db->all('SELECT id FROM media')), 'identical bytes were stored twice');
    $flash = $_SESSION['flash'] ?? '';
    assertContains('again.jpg', is_string($flash) ? $flash : '', 'the message does not name the duplicate');
});

testBothDrivers('an unfinished picture offers to finish, and finishing completes it', function (string $driver) {
    $db = mediaAdminSite($driver);
    adminUpload('/admin/media', [['name' => 'partial.jpg', 'tmp_name' => imageFixture(tmpPath('partial.jpg'), 320, 240)]]);
    $id = (int) ($db->one('SELECT id FROM media')['id'] ?? 0);

    // As a host that killed the request part-way would leave it.
    $db->query("UPDATE media SET status = 'incomplete', variants_json = NULL WHERE id = ?", [$id]);

    assertContains('/finish', mediaAdminGet('/admin/media')->body, 'an unfinished picture offers no way to finish');

    $response = adminUpload('/admin/media/' . $id . '/finish', []);
    assertRedirectedTo('/admin/media', $response);
    assertEquals('complete', (string) ($db->one('SELECT status FROM media WHERE id = ?', [$id])['status'] ?? ''), 'status');
});

testBothDrivers('the library searches by name, and says so when nothing matches', function (string $driver) {
    mediaAdminSite($driver);
    adminUpload('/admin/media', [['name' => 'harbour.jpg', 'tmp_name' => imageFixture(tmpPath('harbour.jpg'), 320, 240)]]);

    assertContains('harbour', mediaAdminGet('/admin/media?q=harb')->body, 'search by part of the name');
    // The term is quoted back, so someone who mistyped can see what was actually searched.
    assertContains('mountain', mediaAdminGet('/admin/media?q=mountain')->body, 'the search term is not repeated back');
});

// The picker loads this and nothing else. It is the library's own listing, so the two can
// never show different cards — the alternative was a second endpoint rendering a second
// idea of what a picture looks like.
testBothDrivers('the picker asks the library for the same cards, with no screen around them', function (string $driver) {
    $db = mediaAdminSite($driver);
    adminUpload('/admin/media', [['name' => 'picker.jpg', 'tmp_name' => imageFixture(tmpPath('picker.jpg'), 320, 240)]]);
    $id = (int) ($db->one('SELECT id FROM media')['id'] ?? 0);

    $fragment = mediaAdminGet('/admin/media?picker=1');
    assertEquals(200, $fragment->status, 'status');

    // A fragment, not a screen: nothing for the picker to strip out.
    assertTrue(!str_contains($fragment->body, '<!doctype'), 'the picker fragment carries the whole admin shell');
    assertTrue(!str_contains($fragment->body, 'admin-nav'), 'the picker fragment carries the navigation');

    // Each card CHOOSES rather than navigates: following a link out of the editor would
    // lose everything typed since the last save.
    assertContains('data-pick="' . $id . '"', $fragment->body, 'a card that chooses the picture');
    assertTrue(!str_contains($fragment->body, 'href="/admin/media/' . $id . '"'), 'the picker links away instead of choosing');
    assertContains('media-thumb', $fragment->body, 'the thumbnail');

    // Search is the library's, not a second implementation of it.
    assertContains('data-pick="' . $id . '"', mediaAdminGet('/admin/media?picker=1&q=picker')->body, 'search by name');
    assertTrue(
        !str_contains(mediaAdminGet('/admin/media?picker=1&q=nothingmatches')->body, 'data-pick='),
        'a search matching nothing still offers pictures',
    );
});

test('the library is admin-only', function () {
    installedSite();
    // No admin_id in the session: this is a redirect to the login screen.
    $_SESSION = [];
    assertRedirectedTo('/admin/login', dispatch('/admin/media'));
});

// The defect this stands over: PHP discards a post over post_max_size whole, token and
// all, so the CSRF check fails first and the answer was "this form has expired" — which
// sends someone to reload the page and send the same oversized file again.
test('a post PHP discarded for its size says so, rather than that the form expired', function () {
    $limit = Bytes::limits()['request'];
    if ($limit <= 0) {
        skip('post_max_size is unlimited on this PHP, so the case cannot arise');
    }
    $db = installedSite();
    createAdmin($db, 'owner@example.com', 'correct horse battery staple');
    $_SESSION['admin_id'] = (int) ($db->one('SELECT id FROM admin')['id'] ?? 0);

    // What the browser announced it was sending, with nothing having arrived.
    $_SERVER['CONTENT_LENGTH'] = (string) ($limit + 1);
    try {
        $response = dispatch('/admin/media', null, 'POST', []);
    } finally {
        unset($_SERVER['CONTENT_LENGTH']);
    }

    assertEquals(413, $response->status, 'status');
    assertContains('per request', $response->body, 'the answer');
    assertTrue(!str_contains($response->body, 'expired'), 'it still blames the form');
});
