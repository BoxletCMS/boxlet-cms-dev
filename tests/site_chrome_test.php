<?php

use App\Core\Settings;
use App\Modules\Media\MediaEncoder;
use App\Modules\Media\MediaUpload;
use App\Modules\Media\MediaVariants;
use App\Modules\Media\MediaWriter;

// What the site's settings put in a page's <head> (PLAN.md D-028): the tab icon and the
// default sharing picture. Asserted on the served HTML, because that is the only place
// the claims in SiteChrome can be checked — a URL built correctly and never emitted is
// the same as no icon at all.
//
// No container override here, unlike the media admin tests. SiteChrome reads variants_json
// and builds a path from it; it never asks whether the file is there. So these need the
// row generated, which is what chromePicture() does into a directory of its own, and
// nothing at render time touches the disk at all.

/**
 * A picture in the library with its variants generated, and its id.
 */
function chromePicture(App\Core\Db $db, string $storage, string $public, string $name): int
{
    $encoder = new MediaEncoder();
    if (!$encoder->supports('jpg')) {
        skip('this machine cannot write jpeg, so no picture can be made', 'images');
    }
    $id = (new MediaUpload($db, $storage, $encoder))
        ->store(imageFixture(tmpPath($name . '.jpg'), 400, 400), $name . '.jpg')['id'];
    (new MediaVariants($db, $encoder, new MediaWriter($encoder), $storage, $public))->generate($id, null);

    return $id;
}

testBothDrivers('a page carries no icon until one is chosen', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    createPage($db, 'en', '', 'Home');

    $body = dispatch('/')->body;

    assertTrue(!str_contains($body, 'rel="icon"'), 'an icon was emitted with none chosen');
    assertTrue(!str_contains($body, 'og:image'), 'a sharing picture was emitted with none chosen');
});

testBothDrivers('the chosen favicon is served from thumb, in the original format', function (string $driver) {
    [$storage, $public] = mediaPaths();
    $db = installedSite(['en' => 'English'], $driver);
    createPage($db, 'en', '', 'Home');
    $id = chromePicture($db, $storage, $public, 'icon');
    Settings::set($db, 'site_favicon', $id);

    $body = dispatch('/')->body;

    // thumb is the only square preset and is generated for every picture, so a favicon
    // needs no preset of its own and nothing regenerated (SPEC §5.5).
    assertContains('/m/thumb/' . $id . '-icon.jpg', $body, 'the icon is not the thumb variant in the source format');
    // The opposite of what <picture> wants, on purpose: a favicon has one URL and no
    // fallback, and a WebP icon is not read by everything a PNG or JPEG is.
    assertTrue(!str_contains($body, '/m/thumb/' . $id . '-icon.webp'), 'the icon was served as webp');
    assertContains('rel="apple-touch-icon"', $body, 'no apple-touch-icon beside it');
});

testBothDrivers('the sharing picture is absolute, because a crawler has no page to resolve it against', function (string $driver) {
    [$storage, $public] = mediaPaths();
    $db = installedSite(['en' => 'English'], $driver);
    createPage($db, 'en', '', 'Home');
    $id = chromePicture($db, $storage, $public, 'share');
    Settings::set($db, 'site_share_image', $id);

    $body = dispatch('/')->body;

    assertContains('property="og:image" content="http://example.test/m/', $body, 'og:image is not an absolute URL');
});

testBothDrivers('the 404 page carries the icon too, and no sharing picture', function (string $driver) {
    [$storage, $public] = mediaPaths();
    $db = installedSite(['en' => 'English'], $driver);
    $id = chromePicture($db, $storage, $public, 'icon404');
    Settings::set($db, 'site_favicon', $id);
    Settings::set($db, 'site_share_image', $id);

    $response = dispatch('/nothing-here');

    assertEquals(404, $response->status, 'status');
    // A browser asks for the icon whatever the status; a link preview of an error page is
    // not worth the row, which is why show() and not render() carries the sharing picture.
    assertContains('rel="icon"', $response->body, 'the 404 page has no icon');
    assertTrue(!str_contains($response->body, 'og:image'), 'the 404 page offers a sharing picture');
});

testBothDrivers('a favicon whose picture was deleted is simply not emitted', function (string $driver) {
    [$storage, $public] = mediaPaths();
    $db = installedSite(['en' => 'English'], $driver);
    createPage($db, 'en', '', 'Home');
    $id = chromePicture($db, $storage, $public, 'gone');
    Settings::set($db, 'site_favicon', $id);
    // The setting is not a foreign key and nothing stops the row going.
    $db->query('DELETE FROM media WHERE id = ?', [$id]);

    $body = dispatch('/')->body;

    assertTrue(!str_contains($body, 'rel="icon"'), 'an icon was emitted for a picture that is gone');
});
