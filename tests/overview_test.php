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

// "unchanged for 1 days" stood on the owner's Overview for as long as the metric existed;
// one day now has its own wording. The days are counted in the SITE's zone, from midnight
// to midnight, so a change late yesterday evening is one day old however late it was.
testBothDrivers('how long the design has stood says it in English', function (string $driver) {
    $db = adminSite($driver);
    $note = static function () use ($db): string {
        foreach (Overview::metrics($db, 'Europe/Zagreb') as $metric) {
            if ($metric['label'] === t('overview.design')) {
                return $metric['note'];
            }
        }

        return '';
    };
    $changed = static function (string $when) use ($db): void {
        $db->query('DELETE FROM activity');
        $at = new DateTimeImmutable($when, new DateTimeZone('Europe/Zagreb'));
        $db->query(
            "INSERT INTO activity (occurred_at, kind, action, subject_id, subject) VALUES (?, 'design', 'saved', NULL, 'Design')",
            [$at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        );
    };

    $changed('today 09:00');
    assertEquals(t('overview.design_today'), $note(), 'changed today');
    $changed('yesterday 23:30');
    assertEquals(t('overview.design_unchanged_one'), $note(), 'one day is not "1 days"');
    $changed('-4 days 09:00');
    assertEquals(t('overview.design_unchanged', ['days' => '4']), $note(), 'four days');
});
