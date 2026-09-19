<?php

use App\Core\Settings;
use App\Modules\Admin\Overview;
use App\Modules\Forms\Form;

// The Overview (PLAN.md D-052). adminSite() comes from pages_admin_test.php, referenceMedia()
// from media_reference_test.php and createPage() from fixtures.php.

/** @return list<string> the titles of what needs attention */
function attentionTitles(App\Core\Db $db): array
{
    return array_column(Overview::attention($db, blockRegistry()), 'title');
}

testBothDrivers('every figure says what it means', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', 'about', 'About');
    createPage($db, 'en', 'soon', 'Soon', false);
    referenceMedia($db, str_repeat('a', 40));

    $metrics = [];
    foreach (Overview::metrics($db, 'Europe/Zagreb') as $metric) {
        $metrics[$metric['label']] = $metric['value'] . ' / ' . $metric['note'];
    }
    assertEquals('2 / ' . t('overview.pages_drafts', ['count' => '1']), $metrics[t('overview.pages')] ?? null, 'pages');
    assertEquals('1 / 100 B', $metrics[t('overview.pictures')] ?? null, 'pictures');
    assertEquals('0 / ' . t('overview.messages_all_read'), $metrics[t('overview.messages')] ?? null, 'messages');
    assertEquals('2 / EN ' . t('overview.languages_main') . ', HR', $metrics[t('overview.languages')] ?? null, 'languages');
    assertTrue(isset($metrics[t('overview.visitors')]), 'visitors while statistics are on');

    Settings::set($db, 'stats_enabled', false);
    $labels = array_column(Overview::metrics($db, 'Europe/Zagreb'), 'label');
    assertTrue(!in_array(t('overview.visitors'), $labels, true), 'visitors while statistics are off');
});

testBothDrivers('what needs attention is found, and goes once it is fixed', function (string $driver) {
    $db = adminSite($driver);
    $picture = referenceMedia($db, str_repeat('b', 40));
    Form::create($db, 'en', 'Contact');

    $titles = attentionTitles($db);
    assertTrue(in_array(t('overview.issue.no_description', ['count' => '1']), $titles, true), 'a picture with no description');
    assertTrue(in_array(t('overview.issue.no_favicon'), $titles, true), 'no favicon');
    assertTrue(in_array(t('overview.issue.no_mail'), $titles, true), 'a form whose messages go nowhere');

    $db->query("INSERT INTO media_meta (media_id, locale, alt, caption, alt_suggested) VALUES (?, 'en', 'A photo', '', 1)", [$picture]);
    Settings::set($db, 'site_favicon', $picture);
    // A guessed description counts as a description: the owner took the "check it" mark
    // off the library in D-038.
    $titles = attentionTitles($db);
    assertTrue(!in_array(t('overview.issue.no_description', ['count' => '1']), $titles, true), 'described, by a guess');
    assertTrue(!in_array(t('overview.issue.no_favicon'), $titles, true), 'a favicon set');
});

testBothDrivers('with nothing waiting, the Overview says so', function (string $driver) {
    $db = adminSite($driver);
    $picture = referenceMedia($db, str_repeat('c', 40));
    Settings::set($db, 'site_favicon', $picture);
    $db->query("INSERT INTO media_meta (media_id, locale, alt, caption, alt_suggested) VALUES (?, 'en', 'A photo', '', 0)", [$picture]);

    assertEquals([], attentionTitles($db), 'issues');
    $screen = dispatch('/admin')->body;
    assertContains(e(t('overview.attention_none')), $screen, 'the calm line');
    assertContains(e(t('activity.recent')), $screen, 'the log');
});
