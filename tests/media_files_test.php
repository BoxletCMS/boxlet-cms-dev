<?php

use App\Modules\Media\MediaFileType;
use App\Modules\Media\MediaReference;

/*
 * FILES FOR VISITORS TO DOWNLOAD, in the library beside the pictures (PLAN.md O-17, D-126).
 */

/** A PDF finfo recognises, as small as one can be. */
function pdfFixture(string $path): string
{
    file_put_contents($path, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

    return $path;
}

test('a document is accepted by its name and its bytes together, and never as a page', function (): void {
    assertEquals('pdf', MediaFileType::documentExtension('Price list.pdf', 'application/pdf'), 'a PDF');
    assertEquals('docx', MediaFileType::documentExtension('Terms.DOCX', 'application/zip'), 'an office file an older libmagic calls a zip');
    assertEquals('csv', MediaFileType::documentExtension('rates.csv', 'text/plain'), 'a CSV finfo calls text');
    assertEquals(null, MediaFileType::documentExtension('page.html', 'text/html'), 'a page');
    assertEquals(null, MediaFileType::documentExtension('drawing.svg', 'image/svg+xml'), 'an SVG');
    assertEquals(null, MediaFileType::documentExtension('notes.pdf', 'text/html'), 'HTML calling itself a PDF');
    assertEquals(null, MediaFileType::documentExtension('run.php', 'text/plain'), 'a script calling itself text');
    assertEquals(null, MediaFileType::documentExtension('archive.pdf.php', 'application/pdf'), 'a double extension ending in a script');
    assertEquals('application/vnd.openxmlformats-officedocument.wordprocessingml.document', MediaFileType::documentMime('docx'), 'served as what it is, not as a zip');
});

testBothDrivers('a document uploaded to the library is stored as a file, whole and outside the web root', function (string $driver) {
    $db = mediaAdminSite($driver);
    $response = adminUpload('/admin/media', [['name' => 'Price list 2026.pdf', 'tmp_name' => pdfFixture(tmpPath('price.pdf'))]]);
    assertEquals(302, $response->status, 'the upload was not accepted');

    $row = $db->one('SELECT * FROM media') ?? fail('nothing was stored');
    assertEquals('file', $row['kind'], 'kind');
    assertEquals('complete', $row['status'], 'a file has nothing left to make');
    assertEquals(null, $row['variants_json'], 'sizes were made from a document');
    assertEquals('application/pdf', $row['mime'], 'mime');
    assertEquals('price-list-2026', $row['filename'], 'its name, generated from what it was called');
    assertTrue(str_starts_with((string) $row['path'], 'uploads/'), 'kept with the originals, outside the web root');

    // The library lists it, filtered either way, and the picture picker never offers it.
    assertContains('price-list-2026', mediaAdminGet('/admin/media?kind=files')->body, 'the files view');
    assertTrue(!str_contains(mediaAdminGet('/admin/media?kind=pictures')->body, 'price-list-2026'), 'the pictures view shows a file');
    assertTrue(!str_contains(mediaAdminGet('/admin/media?picker=1')->body, 'price-list-2026'), 'the picture picker offers a file');
    assertEquals([], MediaReference::choices($db), 'a picture field can choose a file');

    // Its own short page, and none of a picture's actions.
    $id = (int) $row['id'];
    $page = mediaAdminGet('/admin/media/' . $id)->body;
    // No extension at its end (D-128): a host serves `.zip` or `.pdf` as a file on disk and
    // answers 404, and a download so addressed never reached PHP.
    assertContains('/download/' . $id . '/price-list-2026"', $page, 'the address it is downloaded from, ending in its name');
    assertTrue(!str_contains($page, 'data-focal-form') && !str_contains($page, 'data-crop'), 'a file offers a crop or a focal point');
    assertEquals(404, adminUpload('/admin/media/' . $id . '/focal', [], ['x' => '10', 'y' => '10'])->status, 'a focal point for a file');
    assertEquals(404, adminUpload('/admin/media/' . $id . '/crop', [], ['action' => 'new'])->status, 'a crop of a file');
});

testBothDrivers('a file is downloaded as an attachment, and counted for a visitor only', function (string $driver) {
    $db = mediaAdminSite($driver);
    adminUpload('/admin/media', [['name' => 'Price list.pdf', 'tmp_name' => pdfFixture(tmpPath('price2.pdf'))]]);
    $id = (int) ($db->one('SELECT id FROM media')['id'] ?? 0);
    unset($_SESSION['admin_id']);

    // A visitor sends a browser's User-Agent; an empty one is a program (Bots::is()), and a
    // request with none was measured not being counted, correctly.
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
    try {
        $response = dispatch('/download/' . $id . '/price-list');
    } finally {
        unset($_SERVER['HTTP_USER_AGENT']);
    }
    assertEquals(200, $response->status, 'status');
    assertEquals('application/pdf', $response->headers['Content-Type'] ?? null, 'type');
    assertContains('attachment; filename="price-list.pdf"', $response->headers['Content-Disposition'] ?? '', 'saved, never opened');
    assertEquals('nosniff', $response->headers['X-Content-Type-Options'] ?? null, 'the browser may not second-guess it');
    assertContains('sandbox', $response->headers['Content-Security-Policy'] ?? '', 'nothing in it could run');
    assertTrue($response->file !== null && str_starts_with((string) file_get_contents($response->file), '%PDF'), 'the file itself is the body');
    assertEquals(1, (int) ($db->one('SELECT downloads FROM media WHERE id = ?', [$id])['downloads'] ?? 0), 'a visitor\'s download is counted');

    // The admin, known by the session cookie, and a crawler are not.
    $_SERVER['HTTP_COOKIE'] = 'boxlet_session=abc';
    try {
        dispatch('/download/' . $id . '/price-list.pdf');
    } finally {
        unset($_SERVER['HTTP_COOKIE']);
    }
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
    try {
        dispatch('/download/' . $id . '/price-list.pdf');
    } finally {
        unset($_SERVER['HTTP_USER_AGENT']);
    }
    assertEquals(1, (int) ($db->one('SELECT downloads FROM media WHERE id = ?', [$id])['downloads'] ?? 0), 'the admin or a crawler was counted');

    // A picture is not a download, and neither is an id nobody has.
    $db->query("UPDATE media SET kind = 'picture' WHERE id = ?", [$id]);
    assertEquals(404, dispatch('/download/' . $id . '/price-list.pdf')->status, 'a picture served as a download');
    assertEquals(404, dispatch('/download/99999/nothing.pdf')->status, 'a download of nothing');
});

/*
 * THE DOWNLOADS BLOCK (PLAN.md D-127).
 */
testBothDrivers('a Downloads block offers its files with their type and size, and never a download of nothing', function (string $driver) {
    $db = mediaAdminSite($driver);
    adminUpload('/admin/media', [['name' => 'Price list.pdf', 'tmp_name' => pdfFixture(tmpPath('dl.pdf'))]]);
    $file = (int) ($db->one("SELECT id FROM media WHERE kind = 'file'")['id'] ?? 0);
    $registry = App\Core\Blocks::discover(dirname(__DIR__) . '/app/Blocks');
    $content = $registry->normalize('downloads', ['heading' => 'Take it with you', 'items' => [
        ['file' => $file, 'title' => '', 'description' => 'Every service and what it costs.'],
        ['file' => null, 'title' => 'Not chosen yet', 'description' => ''],
    ]]);
    $files = App\Modules\Media\MediaFiles::forBlocks($db, $registry, [['type' => 'downloads', 'content' => $content]]);
    $html = $registry->render('downloads', $content, [], 'list', [], false, 'none', ['files' => $files], 'en');

    assertContains('href="/download/' . $file . '/price-list" download', $html, 'the link to save it, with no extension for a host to claim');
    assertContains('<span class="downloads-type" aria-hidden="true">PDF</span>', $html, 'its type, from the file');
    assertContains('<span class="downloads-title">price-list</span>', $html, 'an empty title is the file\'s own name');
    assertContains('PDF · ', $html, 'and its size beside the type');
    assertContains('downloads-item is-empty', $html, 'an item with no file is marked, for the stylesheet to hide');
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/blocks-downloads.css');
    assertTrue((bool) preg_match('~\.downloads-item\.is-empty\s*\{\s*display:\s*none;~', $css), 'an item with no file is drawn on the page');
});

testBothDrivers('a file field keeps only a file and a picture field only a picture', function (string $driver) {
    $db = mediaAdminSite($driver);
    adminUpload('/admin/media', [['name' => 'terms.pdf', 'tmp_name' => pdfFixture(tmpPath('terms.pdf'))]]);
    $file = (int) ($db->one("SELECT id FROM media WHERE kind = 'file'")['id'] ?? 0);
    $picture = storedPicture($db, 'harbour', []);
    $registry = App\Core\Blocks::discover(dirname(__DIR__) . '/app/Blocks');

    $downloads = App\Modules\Media\MediaReference::resolve($db, $registry, 'downloads', $registry->normalize('downloads', ['items' => [['file' => $file], ['file' => $picture]]]));
    assertEquals([$file, null], array_column($downloads['items'], 'file'), 'a picture offered as a download');
    $hero = App\Modules\Media\MediaReference::resolve($db, $registry, 'hero', $registry->normalize('hero', ['heading' => 'Hi', 'image' => $file]));
    assertEquals(null, $hero['image'], 'a file drawn as a picture');

    // And a file a page offers is refused deletion, naming the page, as a picture is.
    $page = createPage($db, 'en', 'forms', 'Forms', true, [['type' => 'downloads', 'content' => ['items' => [['file' => $file, 'title' => 'Terms']]]]]);
    $library = new App\Modules\Media\MediaLibrary($db, $registry, tmpPath('storage'), tmpPath('admin-media-public'));
    assertEquals([$page => 'Forms'], $library->usedBy($file), 'the page that offers it');
    assertEquals(false, $library->delete($file)['deleted'], 'a file still offered was deleted');
});
