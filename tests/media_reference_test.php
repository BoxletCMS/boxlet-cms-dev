<?php

use App\Modules\Pages\Page;

// A media id that names no picture becomes null on save — D-024's rule for a section's
// background picture, extended to block content (architect's ruling, 2026-09-17).
//
// THE FAILURE THIS PREVENTS was measured, not imagined. The demo shipped image => 1..6
// for pictures that were never uploaded. The first photograph put into the library took
// id 1, and a demo page that had never meant it displayed it — and then refused to let it
// be deleted, naming a page nobody had linked.
//
// Resolution happens on SAVE and not on render: the renderer looks a picture up, finds
// nothing and shows its placeholder, which is what it already did.

/**
 * The stored content of a page's first block.
 *
 * @return array<string, mixed>
 */
function storedBlockContent(App\Core\Db $db, int $pageId): array
{
    $row = $db->one('SELECT content_json FROM page_blocks WHERE page_id = ? ORDER BY sort, id', [$pageId]);
    $content = json_decode((string) ($row['content_json'] ?? ''), true);

    return is_array($content) ? $content : [];
}

/**
 * A picture row with no files behind it: these tests are about references, not encoding.
 */
function referenceMedia(App\Core\Db $db, string $hash): int
{
    $db->query(
        'INSERT INTO media (filename, original_name, path, mime, size, width, height, hash, created_at, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        ['photo', 'photo.jpg', 'uploads/' . $hash . '.jpg', 'image/jpeg', 100, 800, 600, $hash, '2026-01-01 00:00:00', 'complete'],
    );

    return (int) $db->lastInsertId();
}

testBothDrivers('a media field naming no picture is stored as null', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);

    // 999 names nothing. This is exactly the shape the demo shipped.
    $pageId = createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'hero', 'content' => ['heading' => 'Hello', 'image' => 999]],
    ]);

    // array_key_exists, never ??: the null coalescing operator cannot tell a key whose
    // value is null from one that is absent, so `?? 'missing'` reports "missing" for
    // exactly the null this test exists to prove. Asserted separately, so a field that
    // really did vanish from the stored content fails with that as its reason.
    $content = storedBlockContent($db, $pageId);
    assertTrue(array_key_exists('image', $content), 'the media field is not in the stored content at all');
    assertEquals(null, $content['image'], 'a dangling id was stored');
});

testBothDrivers('a media field naming a real picture is kept', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $mediaId = referenceMedia($db, 'hash-kept');

    $pageId = createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'hero', 'content' => ['heading' => 'Hello', 'image' => $mediaId]],
    ]);

    // The rule must not be "null everything": a real reference survives untouched.
    assertEquals($mediaId, storedBlockContent($db, $pageId)['image'] ?? null, 'a real reference was thrown away');
});

testBothDrivers('an id that was valid at save becomes null once the picture is gone', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    $registry = blockRegistry();
    $mediaId = referenceMedia($db, 'hash-later-deleted');

    $pageId = createPage($db, 'en', 'about', 'About', true, [
        ['type' => 'hero', 'content' => ['heading' => 'Hello', 'image' => $mediaId]],
    ]);
    assertEquals($mediaId, storedBlockContent($db, $pageId)['image'] ?? null, 'the reference was not stored to begin with');

    // The picture goes. The library refuses this while a page uses it, so the row is
    // removed directly — the point here is what the NEXT save does with what is left
    // behind, which is the state a restored backup or a hand-edited database produces.
    $db->query('DELETE FROM media WHERE id = ?', [$mediaId]);

    // Saving the page again, unchanged, is what an owner does by pressing Save.
    Page::update($db, $registry, $pageId, [
        'title' => 'About',
        'slug' => 'about',
        'parent_id' => null,
        'status' => 'published',
    ], Page::editable($db, $registry, $pageId));

    $after = storedBlockContent($db, $pageId);
    assertTrue(array_key_exists('image', $after), 'the media field is not in the stored content at all');
    assertEquals(null, $after['image'], 'the reference survived a save after its picture was deleted');
});
