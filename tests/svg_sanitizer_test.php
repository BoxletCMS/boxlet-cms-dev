<?php

use App\Support\SvgSanitizer;

// An SVG logo, cleaned on upload (PLAN.md D-142). Each case below is a known way an SVG
// carries a script or reaches outside itself; each must come out without it. What a logo is
// made of must come out whole. And what is refused is refused with a reason.

/** An SVG around $inner, with a viewBox. */
function svgWith(string $inner, string $rootAttributes = ''): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 120 40"' . $rootAttributes . '>' . $inner . '</svg>';
}

test('what a logo is made of comes through whole', function () {
    $logo = svgWith(
        '<title>Northwind</title>'
        . '<defs><linearGradient id="g" x1="0" x2="1"><stop offset="0" stop-color="#123456"/><stop offset="1" stop-color="#abcdef" stop-opacity=".5"/></linearGradient></defs>'
        . '<style>.mark{fill:url(#g);stroke:#000;stroke-width:2}</style>'
        . '<g transform="translate(4 4)" class="mark"><path d="M0 0h32v32H0z" fill-rule="evenodd"/><circle cx="16" cy="16" r="8" style="fill:#fff;opacity:.9"/></g>'
        . '<text x="44" y="26" font-family="Inter, sans-serif" font-size="20" font-weight="700">Northwind</text>'
        . '<use href="#g"/><use xlink:href="#g"/>',
    );
    $clean = SvgSanitizer::clean($logo);

    assertEquals([120.0, 40.0], [$clean['width'], $clean['height']], 'the size from the viewBox');
    foreach (['<title>Northwind</title>', 'linearGradient', 'stop-color="#abcdef"', '.mark{fill:url(#g);stroke:#000;stroke-width:2}',
        'transform="translate(4 4)"', 'd="M0 0h32v32H0z"', 'fill-rule="evenodd"', 'style="fill:#fff;opacity:.9"',
        'font-family="Inter, sans-serif"', '>Northwind</text>', 'href="#g"'] as $kept) {
        assertContains($kept, $clean['svg'], $kept);
    }
    $again = SvgSanitizer::clean($clean['svg']);
    assertEquals($clean['svg'], $again['svg'], 'a cleaned logo cleans to itself');
});

test('every known way to carry a script or reach outside comes out', function () {
    $attacks = [
        'a script element' => ['<script>alert(1)</script>', 'alert'],
        'a script in CDATA' => ['<script><![CDATA[alert(1)]]></script>', 'alert'],
        'a script by another prefix' => ['<svg:script xmlns:svg="http://www.w3.org/2000/svg">alert(1)</svg:script>', 'alert'],
        'an HTML script inside' => ['<foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><script>alert(1)</script></body></foreignObject>', 'alert'],
        'an event handler' => ['<rect width="10" height="10" onclick="alert(1)"/>', 'onclick'],
        'an event handler on the root' => ['', 'onload'],
        'a javascript link' => ['<a href="javascript:alert(1)"><text>x</text></a>', 'javascript'],
        'a javascript link in xlink' => ['<a xlink:href="javascript:alert(1)"><text>x</text></a>', 'javascript'],
        'a use of another file' => ['<use href="https://evil.example/x.svg#a"/>', 'evil'],
        'a use of a data URI' => ['<use href="data:image/svg+xml;base64,PHN2Zy8+#a"/>', 'data:'],
        'a picture from elsewhere' => ['<image href="https://evil.example/track.png" width="1" height="1"/>', 'evil'],
        'an animation that sets a link' => ['<a><animate attributeName="href" values="javascript:alert(1)"/><text>x</text></a>', 'javascript'],
        'a set that sets a handler' => ['<set attributeName="onmouseover" to="alert(1)"/>', 'alert'],
        'an iframe' => ['<foreignObject><iframe xmlns="http://www.w3.org/1999/xhtml" src="https://evil.example"/></foreignObject>', 'evil'],
        'a stylesheet import' => ['<style>@import url(https://evil.example/x.css);</style>', 'evil'],
        'a style that fetches' => ['<rect width="1" height="1" style="fill:url(https://evil.example/x)"/>', 'evil'],
        'a style escape' => ['<style>.a{fill:\\75 rl(x)}</style>', '\\75'],
        'a style expression' => ['<rect width="1" height="1" style="width:expression(alert(1))"/>', 'expression'],
        'a style that breaks out of its element' => ['<style>.a{fill:red}</style><style>&lt;/style&gt;&lt;script&gt;alert(1)&lt;/script&gt;</style>', 'script'],
        'a fill that fetches' => ['<rect width="1" height="1" fill="url(https://evil.example/#x)"/>', 'evil'],
        'a processing instruction' => ['<?xml-stylesheet href="https://evil.example/x.css"?>', 'evil'],
    ];
    foreach ($attacks as $what => [$inner, $gone]) {
        $root = $what === 'an event handler on the root' ? ' onload="alert(1)"' : '';
        $clean = SvgSanitizer::clean(svgWith('<rect width="10" height="10"/>' . $inner, $root))['svg'];
        assertTrue(!str_contains(strtolower($clean), strtolower($gone)), "{$what}: still in it: {$clean}");
        assertContains('<rect', $clean, "{$what}: the harmless part went too");
    }
});

test('what is refused whole, and why', function () {
    $refused = [
        'an entity that reads a file' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]>' . svgWith('<text>&x;</text>'), t('svg.doctype')],
        'a billion laughs' => ['<!DOCTYPE svg [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;&a;">]>' . svgWith('<text>&b;</text>'), t('svg.doctype')],
        'a DOCTYPE in UTF-16' => [mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE svg []>' . svgWith(''), 'UTF-16'), t('svg.doctype')],
        'an HTML page' => ['<html><body><script>alert(1)</script></body></html>', t('svg.not_svg')],
        'an svg without its namespace' => ['<svg viewBox="0 0 1 1"><rect/></svg>', t('svg.not_svg')],
        'not XML at all' => ['GIF89a', t('svg.not_svg')],
        'no viewBox and no size' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>', t('svg.no_size')],
        'too large' => [svgWith(str_repeat('<rect width="1" height="1"/>', 12000)), t('svg.too_large', ['size' => '256'])],
    ];
    foreach ($refused as $what => [$svg, $message]) {
        assertThrows(static fn () => SvgSanitizer::clean($svg), $message);
    }
});
