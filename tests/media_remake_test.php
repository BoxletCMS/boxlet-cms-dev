<?php

use App\Modules\Media\MediaEncoder;
use App\Modules\Media\MediaRemake;
use App\Modules\Media\MediaUpload;
use App\Modules\Media\MediaVariants;
use App\Modules\Media\MediaWriter;

// Making every picture's sizes again (PLAN.md O-13, D-048). imageFixture() and mediaPaths()
// come from media_test.php.

/**
 * A library of one uploaded, finished picture, and the remake over it.
 *
 * @return array{db: App\Core\Db, id: int, remake: MediaRemake, public: string, storage: string}
 */
function remakeLibrary(string $driver): array
{
    [$storage, $public] = mediaPaths();
    $db = installedSite(['en' => 'English'], $driver);
    $encoder = new MediaEncoder();
    $writer = new MediaWriter($encoder);
    $variants = new MediaVariants($db, $encoder, $writer, $storage, $public);
    $id = (new MediaUpload($db, $storage, $encoder))->store(imageFixture(tmpPath('remake.jpg'), 800, 600), 'remake.jpg')['id'];
    $variants->generate($id, null);

    return ['db' => $db, 'id' => $id, 'remake' => new MediaRemake($db, $encoder, $writer, $variants, $storage, $public), 'public' => $public, 'storage' => $storage];
}

/** @return array<string, mixed> */
function mediaRow(App\Core\Db $db, int $id): array
{
    return $db->one('SELECT * FROM media WHERE id = ?', [$id]) ?? fail("no picture {$id}");
}

testBothDrivers('a remake writes every variant again in place and changes its address', function (string $driver) {
    $lib = remakeLibrary($driver);
    $before = MediaVariants::url(mediaRow($lib['db'], $lib['id']), 'thumb');
    // A variant spoiled on disk: whatever the remake writes must replace it.
    $thumb = glob($lib['public'] . '/m/thumb/' . $lib['id'] . '-remake.*') ?: [];
    assertTrue($thumb !== [], 'no thumb was made to begin with');
    file_put_contents($thumb[0], 'not a picture');

    assertEquals(1, $lib['remake']->start(), 'pictures owed a remake');
    assertEquals(['done' => 1, 'left' => 0], $lib['remake']->step(null), 'one step with no time limit');

    $row = mediaRow($lib['db'], $lib['id']);
    assertTrue(@getimagesize($thumb[0]) !== false, 'the spoiled thumb was not written again');
    assertEquals(1, (int) $row['revision'], 'the revision');
    assertEquals(null, $row['remake'], 'still owed');
    assertEquals('complete', $row['status'], 'the picture is no longer complete');
    assertTrue($before !== MediaVariants::url($row, 'thumb'), 'the address stayed the same, so browsers keep the old file');
    assertEquals([], glob($lib['public'] . '/m/*/*.remake') ?: [], 'a working file was left in the public directory');
});

testBothDrivers('a step cut short keeps what it did and the next one carries on', function (string $driver) {
    $lib = remakeLibrary($driver);
    $lib['remake']->start();

    // No time at all: nothing is done, nothing is lost.
    assertEquals(['done' => 0, 'left' => 1], $lib['remake']->step(0.0), 'a step with no time');
    // Half a pass recorded, as a step stopped by the time limit leaves it.
    $lib['db']->query("UPDATE media SET remake = 'thumb.webp,thumb.jpg' WHERE id = ?", [$lib['id']]);
    assertEquals(['done' => 1, 'left' => 0], $lib['remake']->step(null), 'carrying on');
    assertEquals(1, (int) mediaRow($lib['db'], $lib['id'])['revision'], 'the revision, raised once');
});

testBothDrivers('a picture whose original is gone is left as it is and not owed any more', function (string $driver) {
    $lib = remakeLibrary($driver);
    unlink($lib['storage'] . '/' . mediaRow($lib['db'], $lib['id'])['path']);
    $lib['remake']->start();

    assertEquals(['done' => 1, 'left' => 0], $lib['remake']->step(null), 'the step');
    assertEquals(0, (int) mediaRow($lib['db'], $lib['id'])['revision'], 'a revision for files never made');
});

testBothDrivers('the Media screen starts a pass and continues it until nothing is left', function (string $driver) {
    $db = adminSite($driver);
    storedPicture($db, 'harbour', ['thumb' => ['width' => 200, 'height' => 200, 'formats' => ['jpg']]]);

    assertContains('action="/admin/media/remake"', dispatch('/admin/media')->body, 'the start button');
    assertRedirectedTo('/admin/media#remake', adminPost('/admin/media/remake', []));
    $body = dispatch('/admin/media')->body;
    assertContains('data-auto-continue', $body, 'Continue, while work is owed');
    assertContains(e(t('media.remake_left', ['left' => '1'])), $body, 'how much is left');

    // The fixture has no original on disk, so the step clears it without making anything.
    adminPost('/admin/media/remake/step', []);
    $body = dispatch('/admin/media')->body;
    assertContains(e(t('media.remake_done')), $body, 'done');
    assertTrue(!str_contains($body, 'data-auto-continue'), 'Continue once nothing is left');
});
