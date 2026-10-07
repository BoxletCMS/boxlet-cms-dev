<?php

use App\Modules\Snippets\Snippets;
use App\Modules\Snippets\Tags;
use App\Support\Dates;
use App\Support\PageCache;

/*
 * REPLACEMENT TAGS AND SNIPPETS (PLAN.md D-201, SPEC §5.6): {{year}}, {{lang:switcher}} and
 * {{snippet:name}}, stored as written and drawn on the way out; the Snippets screen.
 */

/**
 * What every tag draws, as Tags::context would work it out, written here.
 *
 * @param array<string, string> $snippets
 * @return array{year: string, switcher: string, snippets: array<string, string>, editor: bool}
 */
function tagContext(array $snippets = [], bool $editor = false): array
{
    return ['year' => '2031', 'switcher' => '<span class="locale-links">EN · HR</span>', 'snippets' => $snippets, 'editor' => $editor];
}

/** The year on the site's clock, as a page drawn now says it. */
function siteYear(App\Core\Db $db): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(Dates::zone($db))))->format('Y');
}

test('each tag draws what it stands for, once, in the words and never in an attribute', function () {
    $context = tagContext(['hours' => 'Mon–Fri <strong>9–17</strong>', 'echo' => 'said {{year}}']);

    assertEquals('<p>© 2031 Studio</p>', Tags::expand('<p>© {{year}} Studio</p>', $context), 'the year');
    assertEquals('<p><span class="locale-links">EN · HR</span></p>', Tags::expand('<p>{{lang:switcher}}</p>', $context), 'the switcher');
    assertEquals('<p>Open Mon–Fri <strong>9–17</strong>.</p>', Tags::expand('<p>Open {{snippet:hours}}.</p>', $context), 'a snippet, its words as written');
    assertEquals('<p>Open .</p>', Tags::expand('<p>Open {{snippet:gone}}.</p>', $context), 'a snippet nobody made draws nothing');

    // Outside the closed list, a tag is words: nothing evaluated, nothing guessed.
    foreach (['{{Year}}', '{{ year }}', '{{snippet:Bad_Name}}', '{{lang:hr}}', '{{page:title}}', '{year}'] as $words) {
        assertEquals('<p>' . $words . '</p>', Tags::expand('<p>' . $words . '</p>', $context), $words . ' was drawn');
    }

    // In an attribute a tag is not one: an address keeps what the owner typed.
    assertEquals('<a href="/x?{{year}}">2031</a>', Tags::expand('<a href="/x?{{year}}">{{year}}</a>', $context), 'inside an attribute');

    // One pass: what a snippet says is not read for tags again.
    assertEquals('<p>said {{year}}</p>', Tags::expand('<p>{{snippet:echo}}</p>', $context), 'a snippet\'s words were read for tags');
});

test('in the editor each tag is a chip with what it draws, in plain words', function () {
    $context = tagContext(['hours' => 'Mon–Fri <strong>9–17</strong><br>Sat'], true);
    $context['switcher'] = 'Language switcher';

    assertEquals('<span class="bx-tag" data-tag="year" contenteditable="false">2031</span>', Tags::expand('{{year}}', $context), 'the year\'s chip');
    assertEquals('<span class="bx-tag" data-tag="snippet:hours" contenteditable="false">Mon–Fri 9–17 Sat</span>', Tags::expand('{{snippet:hours}}', $context), 'a snippet\'s chip');
    assertEquals('<span class="bx-tag" data-tag="lang:switcher" contenteditable="false">Language switcher</span>', Tags::expand('{{lang:switcher}}', $context), 'the switcher\'s chip');
    // Drawing nothing, it says what it is, or the owner could not find it to remove it.
    assertEquals('<span class="bx-tag" data-tag="snippet:gone" contenteditable="false">{{snippet:gone}}</span>', Tags::expand('{{snippet:gone}}', $context), 'a missing snippet\'s chip');
});

testBothDrivers('a snippet is kept as inline words, a paragraph a line', function (string $driver) {
    $db = adminSite($driver);
    Snippets::save($db, 'hours', [
        'en' => '<p>Mon–Fri <strong>9–17</strong></p><p>Sat <a href="/contact">by appointment</a></p><script>alert(1)</script>',
        'hr' => '',
    ]);
    $all = Snippets::all($db);

    assertEquals('Mon–Fri <strong>9–17</strong><br>Sat <a href="/contact">by appointment</a>', $all['hours']['en'] ?? null, 'the English words');
    assertTrue(array_key_exists('hr', $all['hours']), 'a language left empty is kept');
    assertEquals('', $all['hours']['hr'], 'and kept empty');

    // A language with nothing of its own says the first language's: the hours are the hours.
    assertEquals(['hours' => $all['hours']['en']], Snippets::forLocale($db, 'hr', 'en'), 'the fallback');
});

testBothDrivers('the Snippets screen makes, writes and deletes a snippet', function (string $driver) {
    $db = adminSite($driver);

    assertRedirectedTo('/admin/snippets#snippet-opening-hours', adminPost('/admin/snippets', [
        'name' => 'opening-hours',
        'words_en' => '<p>Mon–Fri 9–17</p>',
        'words_hr' => '<p>Pon–Pet 9–17</p>',
    ]));
    assertEquals(t('snippets.created', ['name' => 'opening-hours']), $_SESSION['flash'] ?? null, 'told');
    assertEquals(['en' => 'Mon–Fri 9–17', 'hr' => 'Pon–Pet 9–17'], Snippets::all($db)['opening-hours'] ?? null, 'stored in both languages');

    $screen = dispatch('/admin/snippets')->body;
    assertContains('value="{{snippet:opening-hours}}"', $screen, 'its tag, to copy');
    assertContains('name="words_hr"', $screen, 'a field for each language');
    assertTrue(!str_contains($screen, 'data-rt="tag"'), 'a snippet\'s words offer Insert, which they never draw');
    assertContains('href="/admin/snippets"', $screen, 'the rail has it');

    // A name is checked: its shape, and that it is free.
    foreach (['Opening Hours', '-hours', 'hours-', '', str_repeat('a', 65)] as $bad) {
        $refused = adminPost('/admin/snippets', ['name' => $bad, 'words_en' => '<p>x</p>']);
        assertEquals(422, $refused->status, '"' . $bad . '" was taken as a name');
    }
    $taken = adminPost('/admin/snippets', ['name' => 'opening-hours', 'words_en' => '<p>Other</p>']);
    assertEquals(422, $taken->status, 'a name taken twice');
    assertContains(e(t('snippets.name_taken', ['name' => 'opening-hours'])), $taken->body, 'and why');
    assertContains('<p>Other</p>', html_entity_decode($taken->body), 'the words typed are kept on the screen');
    assertEquals('Mon–Fri 9–17', Snippets::all($db)['opening-hours']['en'] ?? null, 'and the first one is untouched');

    assertRedirectedTo('/admin/snippets#snippet-opening-hours', adminPost('/admin/snippets/opening-hours', ['words_en' => '<p>Mon–Sat 9–17</p>', 'words_hr' => '']));
    assertEquals(['en' => 'Mon–Sat 9–17', 'hr' => ''], Snippets::all($db)['opening-hours'] ?? null, 'written');

    // Without the token nothing happens.
    $forged = dispatch('/admin/snippets/opening-hours/delete', null, 'POST', []);
    assertEquals(403, $forged->status, 'a delete without a token');
    assertTrue(isset(Snippets::all($db)['opening-hours']), 'and the snippet is still there');

    assertRedirectedTo('/admin/snippets', adminPost('/admin/snippets/opening-hours/delete', []));
    assertEquals([], Snippets::all($db), 'deleted, in every language');

    $kinds = array_map(static fn (array $r): string => $r['kind'] . '.' . $r['action'], $db->all("SELECT kind, action FROM activity WHERE kind = 'snippet' ORDER BY id"));
    assertEquals(['snippet.created', 'snippet.saved', 'snippet.deleted'], $kinds, 'the activity log');
});

testBothDrivers('a page draws its tags in its own language, and keeps them as written', function (string $driver) {
    $db = adminSite($driver);
    Snippets::save($db, 'hours', ['en' => 'Mon–Fri 9–17', 'hr' => '']);
    $body = '<p>Open {{snippet:hours}}, since {{year}}. {{lang:switcher}}</p>';
    $en = createPage($db, 'en', 'visit', 'Visit', true, [['type' => 'text', 'content' => ['body' => $body]]]);
    // Its translation, which the switcher leads to (the test database, written directly).
    $hr = (int) App\Modules\Pages\Translations::create($db, blockRegistry(), $en, 'hr');
    $db->query("UPDATE pages SET slug = 'posjet', status = 'published' WHERE id = ?", [$hr]);

    $page = dispatch('/visit')->body;
    assertContains('Open Mon–Fri 9–17, since ' . siteYear($db) . '.', $page, 'the snippet and the year');
    preg_match('~<span class="locale-links">(.*?)</span>~s', $page, $switcher);
    assertEquals(
        '<a href="/visit" hreflang="en" lang="en" aria-current="true">English</a> · <a href="/hr/posjet" hreflang="hr" lang="hr">Hrvatski</a>',
        $switcher[1] ?? null,
        'the switcher, this page in each language',
    );
    assertTrue(!str_contains($page, '{{'), 'a tag reached the visitor');
    assertContains('Open Mon–Fri 9–17', dispatch('/hr/posjet')->body, 'Croatian, with no words of its own, says the first language\'s');

    // Stored as written: next year draws itself.
    $stored = (string) ($db->one('SELECT content_json FROM page_blocks WHERE page_id = ?', [$en])['content_json'] ?? '');
    assertContains('{{year}}', $stored, 'the page keeps the tag');

    // On the canvas each is a chip the editor keeps as the tag.
    $canvas = dispatch("/admin/pages/{$en}/canvas")->body;
    assertContains('<span class="bx-tag" data-tag="snippet:hours" contenteditable="false">Mon–Fri 9–17</span>', $canvas, 'the snippet\'s chip');
    assertContains('<span class="bx-tag" data-tag="year" contenteditable="false">' . siteYear($db) . '</span>', $canvas, 'the year\'s');

    // The editors are told what a chip says.
    $editor = dispatch("/admin/pages/{$en}/form")->body;
    preg_match('~<script type="application/json" id="boxlet-tags">(.*?)</script>~s', $editor, $data);
    $tags = json_decode($data[1] ?? '', true);
    assertEquals(['en' => 'Mon–Fri 9–17', 'hr' => ''], $tags['snippets']['hours'] ?? null, 'the snippets, per language');
    assertEquals(siteYear($db), $tags['year'] ?? null, 'the year');
    assertContains('data-tag-locale="en"', $editor, 'and the field\'s language');
    assertContains('data-rt="tag"', $editor, 'and a rich text field offers Insert');
});

testBothDrivers('the footer draws tags in its words and its small print', function (string $driver) {
    $db = adminSite($driver);
    Snippets::save($db, 'phone', ['en' => '<a href="tel:+385100">01 00</a>']);
    createPage($db, 'en', 'home', 'Home');
    saveChrome([
        'footer_title_en' => 'Studio',
        'footer_text_en' => '<p>Call {{snippet:phone}}</p>',
        'footer_small_print_en' => '© {{year}} <Studio>',
        'look_footer_layout' => 'columns',
        'action' => 'save',
    ]);
    $page = dispatch('/')->body;

    assertContains('Call <a href="tel:+385100">01 00</a>', $page, 'a column\'s words');
    // The small print is plain text: escaped first, then its tags drawn.
    assertContains('© ' . siteYear($db) . ' &lt;Studio&gt;', $page, 'the small print');

    $navigation = dispatch('/admin/navigation')->body;
    assertContains('data-rt="tag"', $navigation, 'the footer\'s words offer Insert');
});

test('a kept page is not handed back across a new year, in any time zone', function () {
    $at = static fn (string $utc): int => (int) strtotime($utc . ' UTC');

    assertTrue(!PageCache::newYearSince($at('2026-06-01 10:00'), $at('2026-06-01 11:00')), 'midsummer');
    assertTrue(!PageCache::newYearSince($at('2026-12-31 08:00'), $at('2026-12-31 09:00')), 'the last morning, nowhere a new year yet');
    // Kept on the last evening at UTC-5, asked for after its midnight: 2027 there.
    assertTrue(PageCache::newYearSince($at('2027-01-01 03:00'), $at('2027-01-01 05:30')), 'west of Greenwich');
    // Kept before midnight at UTC+14, asked for after it.
    assertTrue(PageCache::newYearSince($at('2026-12-31 09:00'), $at('2026-12-31 10:30')), 'the first to see it');
    assertTrue(!PageCache::newYearSince($at('2027-01-02 13:00'), $at('2027-01-02 14:00')), 'and a day later, kept again');
});
