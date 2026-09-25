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
    assertContains('/download/' . $id . '/price-list-2026.pdf', $page, 'the address it is downloaded from');
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
        $response = dispatch('/download/' . $id . '/price-list.pdf');
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
