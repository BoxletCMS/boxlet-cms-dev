<?php

use App\Modules\Scripts\Consent;
use App\Modules\Scripts\ScriptCatalogue;
use App\Modules\Scripts\Scripts;

// The owner's scripts and the box that asks about their cookies (PLAN.md O-73). adminSite() and
// adminPost() come from pages_admin_test.php.

const GA_SNIPPET = "<script async src=\"https://www.googletagmanager.com/gtag/js?id=G-TEST\"></script>\n<script>window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag('js', new Date()); gtag('config', 'G-TEST');</script>";

/** @param array<string, mixed> $script */
function aScript(App\Core\Db $db, array $script): int
{
    return Scripts::save($db, null, $script + ['name' => 'A script', 'code' => '<script>window.x = 1;</script>', 'place' => 'head', 'kind' => 'statistics', 'cookies' => [], 'enabled' => true]);
}

test('a script is checked as it is saved', function () {
    $empty = Scripts::parse([]);
    assertEquals(['name' => t('scripts.name_required'), 'code' => t('scripts.code_required')], $empty['errors'], 'a name and code');
    assertEquals(['head', 'statistics', false], [$empty['script']['place'], $empty['script']['kind'], $empty['script']['enabled']], 'a place, a kind that waits, and off unless ticked');
    assertEquals(t('scripts.code_template'), Scripts::parse(['name' => 'x', 'code' => '<script>a</script></template><script>b</script>'])['errors']['code'] ?? null, 'a </template> of its own refused: it would run what follows at once');
    $parsed = Scripts::parse(['name' => ' Chat ', 'code' => '<script>c</script>', 'place' => 'body', 'kind' => 'marketing', 'enabled' => '1', 'cookies' => [
        ['name' => '_c', 'provider' => 'Chat', 'purpose' => 'Who you are', 'duration' => '1 year'],
        ['name' => '', 'provider' => 'left empty'],
        'not a row',
    ]]);
    assertEquals([], $parsed['errors'], 'a whole one');
    assertEquals(['Chat', 'body', 'marketing', true], [$parsed['script']['name'], $parsed['script']['place'], $parsed['script']['kind'], $parsed['script']['enabled']], 'as typed');
    assertEquals([['name' => '_c', 'provider' => 'Chat', 'purpose' => 'Who you are', 'duration' => '1 year']], $parsed['script']['cookies'], 'a row without a name left out');
});

test('a known service is recognised by its code, with its cookies in the site\'s words', function () {
    $ga = ScriptCatalogue::recognise(GA_SNIPPET, 'hr') ?? fail('Google Analytics not recognised');
    assertEquals(['Google Analytics', 'statistics', false], [$ga['name'], $ga['kind'], $ga['cookieless']], 'Google Analytics');
    assertEquals(['_ga', '_ga_*'], array_column($ga['cookies'], 'name'), 'its cookies on the site');
    assertEquals(site_t('site.consent.catalogue.2y', 'hr'), $ga['cookies'][0]['duration'], 'in Croatian');
    assertEquals('marketing', (ScriptCatalogue::recognise("<script>!function(f,b,e,v,n,t,s){}(window, document,'script','https://connect.facebook.net/en_US/fbevents.js'); fbq('init', '1');</script>", 'en') ?? fail('no Meta'))['kind'], 'Meta Pixel');
    $plausible = ScriptCatalogue::recognise('<script defer data-domain="example.com" src="https://plausible.io/js/script.js"></script>', 'en') ?? fail('no Plausible');
    assertTrue($plausible['cookieless'] && $plausible['kind'] === 'necessary' && $plausible['cookies'] === [], 'Plausible sets none, and needs no consent');
    assertTrue((ScriptCatalogue::recognise('<script src="https://www.googletagmanager.com/gtm.js?id=GTM-X"></script>', 'en') ?? fail('no GTM'))['loads'], 'Tag Manager loads others');
    assertTrue(ScriptCatalogue::recognise('<script>console.log(1)</script>', 'en') === null, 'one it does not know');
});

testBothDrivers('a page carries a necessary script as written, and one that waits inert, with the box', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', 'about', 'About', true);
    $plain = dispatch('/about')->body;
    assertTrue(!str_contains($plain, 'data-consent') && !str_contains($plain, 'site-consent.js'), 'no box where nothing waits: nothing asked');

    aScript($db, ['kind' => 'necessary', 'code' => '<script>window.needed = 1;</script>']);
    $necessary = dispatch('/about')->body;
    assertContains('<script>window.needed = 1;</script>', $necessary, 'a necessary script as written');
    assertTrue(!str_contains($necessary, 'data-consent'), 'and still nothing asked');

    aScript($db, ['kind' => 'statistics', 'code' => '<script>window.counted = 1;</script>', 'cookies' => [['name' => '_ga', 'provider' => 'Google Analytics', 'purpose' => 'Tells visitors apart', 'duration' => '2 years']]]);
    aScript($db, ['kind' => 'marketing', 'place' => 'body', 'code' => '<script>window.ads = 1;</script>', 'enabled' => false]);
    $page = dispatch('/about')->body;
    assertContains('<template data-consent-kind="statistics"><script>window.counted = 1;</script></template>', $page, 'statistics inert until a yes');
    assertTrue(!str_contains($page, 'window.ads'), 'a script turned off is nowhere');
    assertContains('site-consent.js', $page, 'the script that asks');
    assertContains('data-consent-choice="statistics"', $page, 'the box asks about statistics');
    assertTrue(!str_contains($page, 'data-consent-choice="marketing"'), 'and not about a kind no script on the page has');
    assertContains('<code>_ga</code>', $page, 'with the cookies listed');
    assertContains('<code>' . Consent::STORAGE_KEY . '</code>', $page, 'and what the box itself keeps');
    assertContains('data-consent-open hidden>' . e(site_t('site.consent.settings', 'en')), $page, 'Cookie settings in the footer');
    assertTrue(strpos($page, 'data-consent-kind="statistics"') < strpos($page, '</head>'), 'in the head, where it was put');
    createPage($db, 'hr', 'o-nama', 'O nama', true);
    assertContains(e(site_t('site.consent.accept', 'hr')), dispatch('/hr/o-nama')->body, 'in the page\'s own language');
});

testBothDrivers('the owner adds a script, recognised, edits it, and deletes it', function (string $driver) {
    $db = adminSite($driver);
    assertContains(e(t('scripts.no_banner')), dispatch('/admin/scripts')->body, 'nothing asked yet, and said so');

    $refused = adminPost('/admin/scripts', ['name' => '', 'code' => GA_SNIPPET]);
    assertEquals(422, $refused->status, 'a script with no name');
    assertContains('gtag(', $refused->body, 'what was pasted kept');

    $made = adminPost('/admin/scripts', ['name' => 'Analytics', 'code' => GA_SNIPPET]);
    $id = (int) ($db->one('SELECT id FROM scripts')['id'] ?? 0);
    assertRedirectedTo('/admin/scripts/' . $id, $made);
    assertEquals(t('scripts.added_known', ['service' => 'Google Analytics']), $_SESSION['flash'] ?? null, 'recognised');
    $script = Scripts::find($db, $id) ?? fail('not saved');
    assertEquals(['statistics', true, ['_ga', '_ga_*']], [$script['kind'], $script['enabled'], array_column($script['cookies'], 'name')], 'its kind and cookies filled in, and on');
    assertContains(e(t('scripts.banner', ['kinds' => t('scripts.kind.statistics')])), dispatch('/admin/scripts')->body, 'visitors now asked');

    // The owner removes one cookie, then asks for the known ones back.
    $form = ['name' => 'Analytics', 'code' => GA_SNIPPET, 'place' => 'head', 'kind' => 'statistics', 'enabled' => '1', 'cookies' => [['name' => '_ga', 'provider' => 'Google Analytics', 'purpose' => 'Mine', 'duration' => '2 years']]];
    assertRedirectedTo('/admin/scripts/' . $id, adminPost('/admin/scripts/' . $id, $form + ['action' => 'save']));
    $screen = dispatch('/admin/scripts/' . $id)->body;
    assertContains(e(t('scripts.use_known', ['service' => 'Google Analytics'])), $screen, 'the missing ones offered');
    assertContains('data-scripts-check', $screen, 'and the check');
    adminPost('/admin/scripts/' . $id, $form + ['action' => 'known']);
    $after = Scripts::find($db, $id) ?? fail('gone');
    assertEquals(['_ga', '_ga_*'], array_column($after['cookies'], 'name'), 'the missing one added');
    assertEquals('Mine', $after['cookies'][0]['purpose'], 'the owner\'s words kept');

    assertRedirectedTo('/admin/scripts', adminPost('/admin/scripts/' . $id . '/delete', []));
    assertTrue(Scripts::find($db, $id) === null, 'deleted');
    assertEquals('deleted', (string) ($db->one("SELECT action FROM activity WHERE kind = 'script' ORDER BY id DESC")['action'] ?? ''), 'in the activity log');
});
