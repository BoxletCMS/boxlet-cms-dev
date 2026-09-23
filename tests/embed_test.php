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

    $good = $blocks->render('embed', ['url' => 'https://vimeo.com/148751763', 'ratio' => 'wide'], [], 'full', [], false, 'none', [], 'en');
    assertContains('src="https://player.vimeo.com/video/148751763"', $good, 'the built src');
    assertContains('sandbox="allow-scripts allow-same-origin allow-presentation"', $good, 'sandbox');
    assertContains('referrerpolicy="no-referrer"', $good, 'referrer policy');
    // allow-popups would let the frame open a window over the site; allow-top-navigation
    // would let it replace the page. Neither is in the list, and neither may creep in.
    assertTrue(!str_contains($good, 'allow-popups'), 'the sandbox allows popups');
    assertTrue(!str_contains($good, 'allow-top-navigation'), 'the sandbox allows top navigation');
    // A frame with no accessible name is announced as "frame" and nothing else.
    assertContains('title="Video"', $good, 'the frame names itself');

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
        ['url' => 'https://vimeo.com/148751763', 'caption' => 'Our "big" day', 'ratio' => 'wide'],
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
