<?php

use App\Core\Blocks;
use App\Support\Embed;

/*
 * THE EMBED BLOCK IS THE ONLY PLACE IN BOXLET THAT WRITES AN <iframe> (PLAN.md D-105).
 *
 * RichText's whitelist refuses iframe, object, embed and script outright, so every other
 * route into a page is closed. This one is open on purpose, and the rule that keeps it safe
 * is not "the address is validated" — it is that THE ADDRESS IS NEVER USED. Embed::parse()
 * reduces a paste to a provider and an id and BUILDS a src from a fixed template, so a
 * stored value that nothing recognises produces no frame at all.
 *
 * These tests are written against that rule rather than against a list of bad strings: a
 * list can be got round, and "the src is one of four literal prefixes with an id of the
 * right shape after it" cannot.
 */

/**
 * @param list<string> $urls
 * @return list<string> every src the parser will ever hand to a template, for a set of pastes.
 */
function embedSources(array $urls): array
{
    $out = [];
    foreach ($urls as $url) {
        $found = Embed::parse($url);
        if ($found !== null) {
            $out[] = $found['src'];
        }
    }

    return $out;
}

test('a pasted address is reduced to a provider and an id, and the frame is built from those', function (): void {
    $cases = [
        'https://www.youtube.com/watch?v=aqz-KE-bpKQ' => 'https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ',
        'https://youtu.be/aqz-KE-bpKQ?t=90' => 'https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ',
        'https://www.youtube.com/embed/aqz-KE-bpKQ' => 'https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ',
        'https://www.youtube.com/shorts/aqz-KE-bpKQ' => 'https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ',
        'https://m.youtube.com/watch?v=aqz-KE-bpKQ&feature=share' => 'https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ',
        'https://vimeo.com/148751763' => 'https://player.vimeo.com/video/148751763',
        'https://player.vimeo.com/video/148751763' => 'https://player.vimeo.com/video/148751763',
        'https://www.openstreetmap.org/#map=16/45.8131/15.9775'
            => 'https://www.openstreetmap.org/export/embed.html?bbox=15.972007,45.810353,15.982993,45.815847&layer=mapnik',
        'https://www.google.com/maps/@45.8131,15.9775,16z' => 'https://maps.google.com/maps?q=45.8131,15.9775&z=16&output=embed',
        'https://maps.google.hr/maps/place/Ilica/@45.8131,15.9775,16z' => 'https://maps.google.com/maps?q=45.8131,15.9775&z=16&output=embed',
        'https://maps.google.com/?q=45.8131,15.9775' => 'https://maps.google.com/maps?q=45.8131,15.9775&z=15&output=embed',
    ];
    foreach ($cases as $paste => $expected) {
        $found = Embed::parse($paste);
        assertTrue($found !== null, "nothing recognised {$paste}");
        assertEquals($expected, $found['src'] ?? '', $paste);
    }
});

/*
 * WHAT THE PARSER REFUSES, and why each one is here rather than an arbitrary bad string.
 *
 * The first group is the attack: something that wants to be framed and is not one of the
 * four. The second is the near miss, which is where a host check written as a substring
 * search fails — "youtube.com" inside a path, a query, a subdomain or a userinfo field of
 * an address that belongs to somebody else entirely.
 */
test('an address that is not one of the four is not framed at all', function (): void {
    $refused = [
        '',
        '   ',
        'javascript:alert(1)',
        'data:text/html,<script>alert(1)</script>',
        'https://evil.example/player',
        // No scheme: harmless, since the src is rebuilt regardless, and refused anyway —
        // a paste out of a browser's address bar always carries one.
        '//www.youtube.com/embed/aqz-KE-bpKQ',
        'ftp://www.youtube.com/embed/aqz-KE-bpKQ',
        // The near misses: the name is present, the host is not.
        'https://evil.example/www.youtube.com/embed/aqz-KE-bpKQ',
        'https://evil.example/?x=https://www.youtube.com/watch?v=aqz-KE-bpKQ',
        'https://youtube.com.evil.example/watch?v=aqz-KE-bpKQ',
        'https://www.youtube.com@evil.example/watch?v=aqz-KE-bpKQ',
        // The right host, nothing that is an id.
        'https://www.youtube.com/',
        'https://www.youtube.com/watch?v=../../etc/passwd',
        'https://www.youtube.com/watch?v=aqz-KE-bpKQ<script>',
        'https://vimeo.com/channels/staffpicks',
        'https://www.openstreetmap.org/',
        // A coordinate that is not on the globe.
        'https://www.openstreetmap.org/#map=16/95.0/15.9775',
        'https://www.google.com/maps/@45.8131,195.9775,16z',
    ];
    foreach ($refused as $paste) {
        assertEquals(null, Embed::parse($paste), "framed something it should not: {$paste}");
    }
});

/*
 * THE PROPERTY, not the examples: whatever comes out, it starts with one of four literal
 * prefixes and carries nothing after it but an id of that provider's own shape.
 *
 * Written this way because the test above can only ever name the pastes somebody thought
 * of. This one holds for every paste at once, so a change to the parser that let a stray
 * character through would fail here even if nobody added a case for it.
 */
test('every address the parser accepts is one of four, with nothing but an id after it', function (): void {
    $shapes = [
        '~^https://www\.youtube-nocookie\.com/embed/[A-Za-z0-9_-]{11}$~',
        '~^https://player\.vimeo\.com/video/[0-9]{6,12}$~',
        '~^https://www\.openstreetmap\.org/export/embed\.html\?bbox=-?[0-9.]+,-?[0-9.]+,-?[0-9.]+,-?[0-9.]+&layer=mapnik$~',
        '~^https://maps\.google\.com/maps\?q=-?[0-9.]+,-?[0-9.]+&z=[0-9]{1,2}&output=embed$~',
    ];
    // Deliberately hostile pastes on the RIGHT hosts, which is the only place a bad value
    // could reach the src at all.
    $pastes = [
        'https://www.youtube.com/watch?v="onload=alert(1)',
        'https://www.youtube.com/embed/aqz-KE-bpKQ"></iframe><script>alert(1)</script>',
        'https://www.youtube.com/watch?v=aqz-KE-bpKQ&list=</iframe>',
        'https://vimeo.com/148751763"><script>alert(1)</script>',
        'https://www.openstreetmap.org/#map=16/45.8131/15.9775"><script>',
        'https://www.google.com/maps/@45.8131,15.9775,16z/data=!3m1!4b1"><script>',
        'https://www.youtube.com/watch?v=aqz-KE-bpKQ',
        'https://vimeo.com/148751763',
        'https://www.openstreetmap.org/#map=16/45.8131/15.9775',
        'https://www.google.com/maps/@45.8131,15.9775,16z',
    ];
    foreach (embedSources($pastes) as $src) {
        $matched = false;
        foreach ($shapes as $shape) {
            $matched = $matched || preg_match($shape, $src) === 1;
        }
        assertTrue($matched, "the parser produced a src outside the four shapes: {$src}");
    }
});

test('an absurdly long paste is refused before anything is parsed out of it', function (): void {
    $long = 'https://www.youtube.com/watch?v=aqz-KE-bpKQ&x=' . str_repeat('a', 4000);
    assertEquals(null, Embed::parse($long), 'a 4kB address was parsed');
});

/*
 * AND THE BLOCK ITSELF: the sandbox, and what an unrecognised address draws.
 *
 * The note is in the markup on the page as well as in the canvas — blocks.css hides it and
 * canvas.css shows it, the same device the Columns block uses for an empty column — so this
 * asserts on the CLASS, which is what decides where it is seen, rather than on its absence.
 */
test('the embed block sandboxes its frame, and draws none at all for an address it does not know', function (): void {
    $blocks = Blocks::discover(dirname(__DIR__) . '/app/Blocks');

    // OpenStreetMap, the one provider still framed at once: since D-147 a video and a Google
    // map wait for a press, and that case is tested below with the same list.
    $good = $blocks->render('embed', ['url' => 'https://www.openstreetmap.org/#map=16/45.8131/15.9775', 'ratio' => 'wide'], [], 'full', [], false, 'none', [], 'en');
    assertContains('src="https://www.openstreetmap.org/export/embed.html?bbox=15.972007,45.810353,15.982993,45.815847&amp;layer=mapnik"', $good, 'the built src');
    assertContains('sandbox="allow-scripts allow-same-origin allow-presentation"', $good, 'sandbox');
    // The origin, never the page's path: YouTube refuses a frame that sends no Referer at
    // all (Error 153), which no-referrer did until D-146.
    assertContains('referrerpolicy="strict-origin-when-cross-origin"', $good, 'referrer policy');
    // allow-popups would let the frame open a window over the site; allow-top-navigation
    // would let it replace the page. Neither is in the list, and neither may creep in.
    assertTrue(!str_contains($good, 'allow-popups'), 'the sandbox allows popups');
    assertTrue(!str_contains($good, 'allow-top-navigation'), 'the sandbox allows top navigation');
    // A frame with no accessible name is announced as "frame" and nothing else.
    assertContains('title="Map"', $good, 'the frame names itself');

    $bad = $blocks->render('embed', ['url' => 'https://evil.example/player', 'ratio' => 'wide'], [], 'full', [], false, 'none', [], 'en');
    assertTrue(!str_contains($bad, '<iframe'), 'an unrecognised address was framed anyway');
    assertTrue(!str_contains($bad, 'evil.example'), 'an unrecognised address was written into the page');
    assertContains('ratio-wide is-empty', $bad, 'the block does not mark itself empty, so nothing hides it on the page');
    assertContains('embed-unknown', $bad, 'the editor is told nothing about why the block is blank');
});

/*
 * THE CAPTION IS THE FRAME'S NAME WHEN THERE IS ONE, and it is escaped like everything
 * else: it lands in an attribute, which is the one place in this template where a quote
 * would end the value and start another attribute.
 */
test('a caption names the frame, escaped', function (): void {
    $html = Blocks::discover(dirname(__DIR__) . '/app/Blocks')->render(
        'embed',
        ['url' => 'https://www.openstreetmap.org/#map=16/45.8131/15.9775', 'caption' => 'Our "big" day', 'ratio' => 'wide'],
        [],
        'full',
        [],
        false,
        'none',
        [],
        'en',
    );
    assertContains('title="Our &quot;big&quot; day"', $html, 'the caption names the frame');
    assertTrue(!str_contains($html, 'title="Our "big" day"'), 'the caption was written unescaped');
});

/*
 * NOTHING OF THE PROVIDER'S BEFORE A PRESS (PLAN.md D-147).
 *
 * Measured 2026-09-30: a youtube-nocookie frame wrote two localStorage keys and an IndexedDB
 * database into the visitor's browser, and made seven requests to Google, the moment the page
 * opened. So a video or a Google map is a LINK until it is pressed: no iframe, no address of
 * the provider's that a browser would fetch — only the link's own href, which it does not.
 */
test('a video and a Google map wait for a press, and an OpenStreetMap map does not', function (): void {
    $cases = [
        'https://www.youtube.com/watch?v=aqz-KE-bpKQ' => ['youtube', 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', true],
        'https://vimeo.com/148751763' => ['vimeo', 'https://vimeo.com/148751763', true],
        'https://www.google.com/maps/@45.8131,15.9775,16z' => ['googlemaps', 'https://maps.google.com/maps?q=45.8131,15.9775&z=16', true],
        'https://www.openstreetmap.org/#map=16/45.8131/15.9775' => ['openstreetmap', 'https://www.openstreetmap.org/#map=16/45.8131/15.9775', false],
    ];
    foreach ($cases as $paste => [$provider, $open, $deferred]) {
        $found = Embed::parse($paste) ?? fail("nothing recognised {$paste}");
        assertEquals($provider, $found['provider'], "provider of {$paste}");
        assertEquals($open, $found['open'], "the address that opens {$paste} on its own site");
        assertEquals($deferred, $found['deferred'], "whether {$paste} waits");
    }

    $blocks = Blocks::discover(dirname(__DIR__) . '/app/Blocks');
    $video = $blocks->render('embed', ['url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', 'caption' => 'Our "big" day', 'ratio' => 'wide'], [], 'full', [], false, 'none', [], 'en');
    assertTrue(!str_contains($video, '<iframe'), 'a video is framed before it is pressed');
    // The only place the player's address appears is the data the press reads; a browser
    // fetches neither a data attribute nor a link's href on its own.
    assertEquals(1, substr_count($video, 'youtube-nocookie.com'), 'the player address outside the press');
    assertContains('data-embed-src="https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ?autoplay=1"', $video, 'what the press frames, playing');
    assertContains('href="https://www.youtube.com/watch?v=aqz-KE-bpKQ"', $video, 'without a script, the press opens the video on YouTube');
    assertContains('data-embed-sandbox="allow-scripts allow-same-origin allow-presentation"', $video, 'the sandbox the frame will get');
    assertTrue(!str_contains($video, 'allow-popups') && !str_contains($video, 'allow-top-navigation'), 'the deferred sandbox widened');
    assertContains('data-embed-title="Our &quot;big&quot; day"', $video, 'the frame\'s name, escaped');
    assertContains('Play video', $video, 'the press says what it does');
    assertContains('YouTube · loads only when pressed', $video, 'and where it comes from');
    // No cover chosen: the placeholder frame, never an <img> of the provider's.
    assertContains('is-bare', $video, 'the bare frame');
    assertTrue(!str_contains($video, 'ytimg'), 'a thumbnail from YouTube is drawn');

    $map = $blocks->render('embed', ['url' => 'https://www.google.com/maps/@45.8131,15.9775,16z', 'ratio' => 'square'], [], 'full', [], false, 'none', [], 'hr');
    assertTrue(!str_contains($map, '<iframe'), 'a Google map is framed before it is pressed');
    assertContains('Prikaži kartu', $map, 'a map says Show map, in the page\'s language');
});

test('a video\'s cover is the site\'s own picture', function (): void {
    $picture = [
        'id' => 7, 'filename' => 'spain', 'width' => 1280, 'height' => 720, 'focalX' => 50, 'focalY' => 50,
        'variants' => ['wide' => ['width' => 1200, 'height' => 630, 'formats' => ['jpg']]], 'alt' => 'Spain', 'version' => '1',
    ];
    $html = Blocks::discover(dirname(__DIR__) . '/app/Blocks')->render(
        'embed',
        ['url' => 'https://vimeo.com/148751763', 'poster' => 7, 'ratio' => 'wide'],
        [],
        'full',
        [7 => $picture],
        false,
        'none',
        [],
        'en',
    );
    assertContains('/m/wide/7-spain', $html, 'the cover, from the site\'s own variants');
    assertTrue(!str_contains($html, 'is-bare'), 'a cover was drawn as the bare frame');
});
