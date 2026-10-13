<?php

use App\Core\Request;
use App\Core\Settings;
use App\Modules\Media\MediaLibrary;
use App\Modules\Pages\PageSeo;
use App\Modules\Pages\PageSubmission;

// WHAT A LINK TO A PAGE LOOKS LIKE WHERE IT IS SHARED (PLAN.md D-242, the owner): Open Graph and
// Twitter tags from what the page already says, its own sharing picture over the site's, and
// WebSite structured data on a language's home page. Asserted on the served HTML.

/**
 * The page's seo_json, written as the editors write it. The kept pages are emptied, as every
 * save through the admin empties them: a page served before would otherwise be served again.
 *
 * @param array{title?: string, description?: string, image?: int|null} $seo
 */
function sharingSeo(App\Core\Db $db, int $pageId, array $seo): void
{
    $db->query('UPDATE pages SET seo_json = ? WHERE id = ?', [PageSeo::json($seo + ['title' => '', 'description' => '']), $pageId]);
    App\Support\PageCache::clear();
}

/**
 * A picture with its sizes, of its own size: the library keeps one row for one file, so two
 * pictures made alike (chromePicture() makes every one 400×400) are the same picture.
 */
function sharingPicture(App\Core\Db $db, string $storage, string $public, string $name, int $size): int
{
    $encoder = new App\Modules\Media\MediaEncoder();
    if (!$encoder->supports('jpg')) {
        skip('this machine cannot write jpeg, so no picture can be made', 'images');
    }
    $id = (new App\Modules\Media\MediaUpload($db, $storage, $encoder))
        ->store(imageFixture(tmpPath($name . '.jpg'), $size, $size), $name . '.jpg')['id'];
    (new App\Modules\Media\MediaVariants($db, $encoder, new App\Modules\Media\MediaWriter($encoder), $storage, $public))->generate($id, null);

    return $id;
}

testBothDrivers('a page tells a link preview its title, description, address and site', function (string $driver) {
    $db = installedSite(['en' => 'English'], $driver);
    Settings::set($db, 'site_name', 'Printworks & Co');
    createPage($db, 'en', '', 'Home');
    $about = createPage($db, 'en', 'about', 'About');
    sharingSeo($db, $about, ['title' => 'About the Printworks', 'description' => 'Who runs the press, and since when.']);

    $body = dispatch('/about')->body;
    assertContains('<meta property="og:type" content="website">', $body, 'og:type');
    assertContains('<meta property="og:title" content="About the Printworks">', $body, 'og:title is the title in search results');
    assertContains('<meta property="og:description" content="Who runs the press, and since when.">', $body, 'og:description');
    assertContains('<meta property="og:url" content="http://example.test/about">', $body, 'og:url is the canonical address');
    assertContains('<meta property="og:site_name" content="Printworks &amp; Co">', $body, 'og:site_name, escaped');
    assertContains('<meta name="twitter:card" content="summary">', $body, 'the small card without a picture');
    assertTrue(!str_contains($body, 'og:image'), 'no picture, no og:image');
    assertTrue(!str_contains($body, '"@type":"WebSite"'), 'WebSite is the home page\'s, not a subpage\'s');

    // A page that gives no description gives none here either (D-004).
    $bare = dispatch('/')->body;
    assertContains('<meta property="og:title" content="Home">', $bare, 'the page title stands in');
    assertTrue(!str_contains($bare, 'og:description'), 'no description repeated from the title');
});

testBothDrivers('a language\'s home page names the site for search engines', function (string $driver) {
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski'], $driver);
    Settings::set($db, 'site_name', 'Printworks');
    createPage($db, 'en', '', 'Home');
    createPage($db, 'hr', '', 'Početna');

    assertContains('{"@context":"https://schema.org","@type":"WebSite","name":"Printworks","url":"http://example.test/","inLanguage":"en"}', dispatch('/')->body, 'the main language');
    assertContains('"url":"http://example.test/hr/","inLanguage":"hr"', dispatch('/hr/')->body, 'and the other');

    Settings::set($db, 'site_name', '');
    assertTrue(!str_contains(dispatch('/')->body, '"WebSite"'), 'no name, no claim');
});

testBothDrivers('a page\'s own sharing picture wins over the site\'s, and one that is gone falls back', function (string $driver) {
    [$storage, $public] = mediaPaths();
    $db = installedSite(['en' => 'English'], $driver);
    createPage($db, 'en', '', 'Home');
    $about = createPage($db, 'en', 'about', 'About');
    $site = sharingPicture($db, $storage, $public, 'site-share', 400);
    $own = sharingPicture($db, $storage, $public, 'own-share', 420);
    Settings::set($db, 'site_share_image', $site);

    assertContains('-site-share.', dispatch('/about')->body, 'the site\'s picture, with none of its own');
    sharingSeo($db, $about, ['image' => $own]);
    $body = dispatch('/about')->body;
    assertContains('property="og:image" content="http://example.test/m/', $body, 'absolute');
    assertContains('-own-share.', $body, 'its own picture');
    assertTrue(!str_contains($body, '-site-share.'), 'not the site\'s as well');
    assertContains('<meta name="twitter:card" content="summary_large_image">', $body, 'the large card with a picture');

    sharingSeo($db, $about, ['image' => 999999]);
    assertContains('-site-share.', dispatch('/about')->body, 'a picture that is gone reads as none: the site\'s');

    assertTrue(!str_contains(dispatch('/nowhere')->body, 'og:'), 'an error page carries no preview at all');
});

test('the plain editor sets and keeps a page\'s sharing picture', function () {
    $request = static fn (array $body): Request => new Request('POST', '/admin/pages/1', '', [], $body, []);
    $stored = PageSeo::json(['title' => 'T', 'description' => 'D', 'image' => 7]);

    assertEquals(7, PageSeo::of(['seo_json' => PageSubmission::seo($request(['seo_title' => 'T']), $stored)])['image'], 'a form without the field keeps it');
    assertEquals(12, PageSeo::of(['seo_json' => PageSubmission::seo($request(['seo_title' => 'T', 'seo_image' => '12']), $stored)])['image'], 'chosen');
    assertEquals(null, PageSeo::of(['seo_json' => PageSubmission::seo($request(['seo_title' => 'T', 'seo_image' => '']), $stored)])['image'], 'none: the site\'s');
    assertEquals(null, PageSeo::of(['seo_json' => '{"image":"7"}'])['image'], 'only an id is an id');
});

testBothDrivers('a picture a page is shared with is in use, and is not deleted from under it', function (string $driver) {
    [$storage, $public] = mediaPaths();
    $db = installedSite(['en' => 'English'], $driver);
    $about = createPage($db, 'en', 'about', 'About');
    $own = sharingPicture($db, $storage, $public, 'in-use', 400);
    $other = sharingPicture($db, $storage, $public, 'not-in-use', 420);
    assertTrue($own !== $other, 'two pictures, not one');
    sharingSeo($db, $about, ['image' => $own]);
    $library = new MediaLibrary($db, blockRegistry(), $storage, $public);

    assertEquals([$about => 'About'], $library->usedBy($own), 'named as used on the page');
    assertEquals([], $library->usedBy($other), 'another picture is not');
    assertEquals(1, $library->usage()[$own]['pages'] ?? 0, '"Used on" counts the page');
    assertTrue(!$library->delete($own)['deleted'], 'refused while the page is shared with it');
});

testBothDrivers('the builder\'s page tab offers the sharing picture, the chosen one selected', function (string $driver) {
    [$storage, $public] = mediaPaths();
    $db = adminSite($driver);
    $about = createPage($db, 'en', 'about', 'About');
    $own = sharingPicture($db, $storage, $public, 'tab-share', 400);
    sharingSeo($db, $about, ['image' => $own]);

    $body = dispatch('/admin/pages/' . $about)->body;
    assertContains('id="pb-seo-image" data-pb-page="seo_image" data-media-field', $body, 'the picker, bound to the page');
    assertContains('<option value="' . $own . '" data-thumb=', $body, 'the picture, with its thumbnail');
    assertTrue(str_contains($body, '<option value="' . $own . '" data-thumb="') && preg_match('~<option value="' . $own . '"[^>]* selected>~', $body) === 1, 'selected');
});
