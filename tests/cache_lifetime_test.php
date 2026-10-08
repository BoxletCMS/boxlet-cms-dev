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
        && preg_match('~^/cache/tokens\.[0-9a-f]+\.css$~', $url) !== 1
        && preg_match('~^/m/logo/[^/?]+-[0-9a-f]{12}\.svg$~', $url) !== 1));
}

testBothDrivers('every static file the site and the admin link changes its address when it changes', function (string $driver) {
    $db = adminSite($driver);
    $picture = storedPicture($db, 'harbour', ['hero' => ['width' => 1920, 'height' => 1080, 'formats' => ['avif', 'webp', 'jpg']]]);
    $id = createPage($db, 'en', 'about', 'About', true, [['type' => 'hero', 'content' => ['heading' => 'Welcome', 'image' => $picture]]]);

    $visitor = staticAddresses(dispatch('/about')->body);
    // Not vacuous: the page's own stylesheets, its design, its fonts and its picture are there.
    foreach (['~^/assets/[a-z-]+\.css~', '~^/cache/tokens\.~', '~^/assets/fonts/~', '~^/m/hero/~'] as $kind) {
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
