<?php

use App\Modules\Pages\Translations;

// What a visitor gets in a site of several languages (PLAN.md D-043, step 4), asserted on
// the served page.

/**
 * The switcher's links as served: language code => href.
 *
 * @return array<string, string>
 */
function switcherLinks(string $body): array
{
    if (preg_match('~<nav class="locale-switcher".*?</nav>~s', $body, $nav) !== 1) {
        return [];
    }
    preg_match_all('~<a href="([^"]*)" hreflang="([^"]*)"~', $nav[0], $links, PREG_SET_ORDER);
    $out = [];
    foreach ($links as $link) {
        $out[$link[2]] = $link[1];
    }

    return $out;
}

testBothDrivers('the switcher leads to this page in each language, else that language\'s home, else nowhere', function (string $driver) {
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski', 'de' => 'Deutsch'], $driver);
    createPage($db, 'hr', '', 'Početna');
    $about = createPage($db, 'en', 'about', 'About');
    $onama = (int) Translations::create($db, blockRegistry(), $about, 'hr');
    $db->query("UPDATE pages SET slug = 'o-nama', status = 'published' WHERE id = ?", [$onama]);
    createPage($db, 'en', 'team', 'Team');

    // Translated into Croatian; German has neither this page nor a home.
    assertEquals(['en' => '/about', 'hr' => '/hr/o-nama'], switcherLinks(dispatch('/about')->body), 'from a translated page');
    assertEquals(['en' => '/about', 'hr' => '/hr/o-nama'], switcherLinks(dispatch('/hr/o-nama')->body), 'from its translation');
    // Not translated: Croatian is offered as its home page, not as a page that is not there.
    assertEquals(['en' => '/team', 'hr' => '/hr/'], switcherLinks(dispatch('/team')->body), 'from an untranslated page');
    // A translation still a draft is not somewhere a visitor can be sent.
    $db->query("UPDATE pages SET status = 'draft' WHERE id = ?", [$onama]);
    assertEquals(['en' => '/about', 'hr' => '/hr/'], switcherLinks(dispatch('/about')->body), 'with the translation unpublished');
});

testBothDrivers('a page published in several languages names each for search engines, the main one as the default', function (string $driver) {
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski'], $driver);
    $about = createPage($db, 'en', 'about', 'About');
    $body = dispatch('/about')->body;
    assertTrue(!str_contains($body, 'rel="alternate"'), 'alternates for a page in one language');

    $onama = (int) Translations::create($db, blockRegistry(), $about, 'hr');
    $db->query("UPDATE pages SET slug = 'o-nama', status = 'published' WHERE id = ?", [$onama]);
    $body = dispatch('/hr/o-nama')->body;
    assertContains('<link rel="alternate" hreflang="en" href="http://example.test/about">', $body, 'the English version');
    assertContains('<link rel="alternate" hreflang="hr" href="http://example.test/hr/o-nama">', $body, 'the page itself');
    assertContains('<link rel="alternate" hreflang="x-default" href="http://example.test/about">', $body, 'the default');
});

testBothDrivers('a picture without alt text in the page\'s language takes the main language\'s', function (string $driver) {
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski'], $driver);
    $picture = storedPicture($db, 'harbour', ['card' => ['width' => 600, 'height' => 400, 'formats' => ['jpg']], 'wide' => ['width' => 1200, 'height' => 630, 'formats' => ['jpg']]]);
    $db->query('INSERT INTO media_meta (media_id, locale, alt, caption) VALUES (?, ?, ?, ?)', [$picture, 'en', 'The harbour at dawn', '']);
    $blocks = [['type' => 'image_text', 'content' => ['body' => '<p>x</p>', 'image' => $picture]]];
    createPage($db, 'hr', 'luka', 'Luka', true, $blocks);

    assertContains('alt="The harbour at dawn"', dispatch('/hr/luka')->body, 'no Croatian alt: the English one');

    // An alt written in Croatian wins — and one deliberately left empty stays empty: an
    // empty alt is a decision that the picture is decoration, not a gap to fill.
    $db->query('INSERT INTO media_meta (media_id, locale, alt, caption) VALUES (?, ?, ?, ?)', [$picture, 'hr', 'Luka u zoru', '']);
    assertContains('alt="Luka u zoru"', dispatch('/hr/luka')->body, 'the Croatian alt');
    $db->query("UPDATE media_meta SET alt = '' WHERE media_id = ? AND locale = 'hr'", [$picture]);
    assertContains('alt=""', dispatch('/hr/luka')->body, 'an alt left empty on purpose');
});
