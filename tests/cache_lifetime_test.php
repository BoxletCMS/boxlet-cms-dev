<?php

use App\Modules\Design\Typography;

// WHAT A BROWSER MAY KEEP A YEAR (PLAN.md D-202). public/.htaccess, and the nginx map in the
// README, tell a browser to keep any static file whose address carries ?v= or a hash in its
// name for a year, without asking again. That is safe only while every such address changes
// when its file does: a link written without a version would leave visitors on the old file
// for a year. These stand over that: every address the site and the admin hand out under
// /assets/, /cache/ and /m/ carries one.

/**
 * Every address under /assets/, /cache/ or /m/ in a page's href, src and srcset.
 *
 * @return list<string>
 */
function staticAddresses(string $html): array
{
    preg_match_all('~\s(?:href|src|srcset)="([^"]+)"~', $html, $attributes);
    $found = [];
    foreach ($attributes[1] as $value) {
        foreach (explode(',', html_entity_decode($value)) as $candidate) {
            $url = strtok(trim($candidate), ' ');
            if (is_string($url) && preg_match('~^/(assets|cache|m)/~', $url) === 1) {
                $found[] = $url;
            }
        }
    }

    return array_values(array_unique($found));
}

/**
 * Those of $addresses that would be kept a year without changing with their file.
 *
 * @param list<string> $addresses
 * @return list<string>
 */
function unversioned(array $addresses): array
{
    return array_values(array_filter($addresses, static fn (string $url): bool => preg_match('~[?&]v=[^&]+~', $url) !== 1
        && preg_match('~^/cache/(tokens|site)\.[0-9a-f]+\.css$~', $url) !== 1
        && preg_match('~^/m/logo/[^/?]+-[0-9a-f]{12}\.svg$~', $url) !== 1));
}

testBothDrivers('every static file the site and the admin link changes its address when it changes', function (string $driver) {
    $db = adminSite($driver);
    $picture = storedPicture($db, 'harbour', ['hero' => ['width' => 1920, 'height' => 1080, 'formats' => ['avif', 'webp', 'jpg']]]);
    $id = createPage($db, 'en', 'about', 'About', true, [['type' => 'hero', 'content' => ['heading' => 'Welcome', 'image' => $picture]]]);

    $visitor = staticAddresses(dispatch('/about')->body);
    // Not vacuous: the page's own stylesheets, its design, its fonts and its picture are there.
    foreach (['~^/cache/site\.~', '~^/cache/tokens\.~', '~^/assets/fonts/~', '~^/m/hero/~'] as $kind) {
        assertTrue(preg_grep($kind, $visitor) !== [], "the page links nothing matching {$kind}");
    }
    assertEquals([], unversioned($visitor), '/about');

    foreach (['/admin', '/admin/pages', "/admin/pages/{$id}", "/admin/pages/{$id}/form", '/admin/appearance', '/admin/navigation', '/admin/settings', '/admin/snippets', '/admin/media', "/admin/media/{$picture}"] as $path) {
        assertEquals([], unversioned(staticAddresses(dispatch($path)->body)), $path);
    }
});

test('the fonts a design asks for carry their version, the same in @font-face and in the preload', function () {
    $css = Typography::fontFaces(['playfair-display', 'inter'], '../assets/fonts');
    preg_match_all('~url\("([^"]+)"\)~', $css, $urls);
    assertTrue($urls[1] !== [], 'no @font-face');
    foreach ($urls[1] as $url) {
        assertTrue(preg_match('~\.woff2\?v=[0-9a-f]{12}$~', $url) === 1, "{$url} has no version");
    }
    foreach (Typography::preloads('playfair-display', 'inter', 650) as $path) {
        assertContains('url("../assets/fonts/' . Typography::versioned($path) . '")', $css, "the preload of {$path} is the address @font-face fetches");
    }
});

test('the visitor\'s stylesheets are bundled once, keep their addresses right, and an old bundle outlives the kept pages', function () {
    $dir = tmpPath('bundle');
    removeTree($dir);
    $name = App\Support\SiteStyles::file($dir);
    assertTrue(preg_match('~^site\.[0-9a-f]{12}\.css$~', $name) === 1, "named {$name}");
    $css = (string) file_get_contents($dir . '/' . $name);
    assertEquals(App\Support\SiteStyles::css(), $css, 'what is written is the bundle');
    // A sheet's own relative address points from cache/ to assets/; a data: one is untouched.
    assertContains('url("../assets/embed-youtube.svg")', $css, 'the embed mark from cache/');
    assertTrue(!str_contains($css, 'url("embed-youtube.svg")'), 'a relative address left as written');

    // An older bundle stays while a kept page may still name it, and goes after. A folder of
    // its own: the answer for one is worked out once a request.
    $dir = tmpPath('bundle-old');
    removeTree($dir);
    mkdir($dir, 0700, true);
    file_put_contents($dir . '/site.aaaaaaaaaaaa.css', 'a{}');
    file_put_contents($dir . '/site.bbbbbbbbbbbb.css', 'b{}');
    touch($dir . '/site.bbbbbbbbbbbb.css', time() - App\Support\PageCache::MAX_AGE - 7200);
    App\Support\SiteStyles::file($dir);
    assertTrue(is_file($dir . '/site.aaaaaaaaaaaa.css'), 'a bundle of the last day was removed');
    assertTrue(!is_file($dir . '/site.bbbbbbbbbbbb.css'), 'a bundle older than any kept page was kept');
});

testBothDrivers('the first picture of the first section is asked for first, and only that one (D-205)', function (string $driver) {
    $db = adminSite($driver);
    $variants = ['hero' => ['width' => 1920, 'height' => 1080, 'formats' => ['avif', 'jpg']], 'card' => ['width' => 600, 'height' => 400, 'formats' => ['avif', 'jpg']], 'wide' => ['width' => 1200, 'height' => 630, 'formats' => ['avif', 'jpg']]];
    $a = storedPicture($db, 'harbour', $variants);
    $b = storedPicture($db, 'atelier', $variants);
    $cards = ['type' => 'cards', 'content' => ['heading' => 'Three', 'items' => [['heading' => 'One', 'image' => $a], ['heading' => 'Two', 'image' => $b]]]];
    $id = createPage($db, 'en', 'about', 'About', true, [['type' => 'hero', 'content' => ['heading' => 'Welcome', 'image' => $a]], $cards]);

    $page = dispatch('/about')->body;
    preg_match_all('~<img\b[^>]*>~', $page, $images);
    $high = array_values(preg_grep('~fetchpriority="high"~', $images[0]) ?: []);
    assertEquals(1, count($high), 'pictures asked for first');
    $first = $high[0] ?? '';
    assertContains('alt=', $first, 'an image');
    assertTrue(str_contains($first, '/m/hero/') && !str_contains($first, 'loading='), 'the hero\'s, which is not lazy');

    // A first section with no picture: the cards below it are lazy, and none is asked first.
    createPage($db, 'en', 'plain', 'Plain', true, [['type' => 'text', 'content' => ['body' => '<p>Words</p>']], $cards]);
    assertTrue(!str_contains(dispatch('/plain')->body, 'fetchpriority'), 'a lazy picture asked for first');
    // Nor in the editor's canvas, which is no visitor's page.
    assertTrue(!str_contains(dispatch("/admin/pages/{$id}/canvas")->body, 'fetchpriority'), 'the canvas');
});
