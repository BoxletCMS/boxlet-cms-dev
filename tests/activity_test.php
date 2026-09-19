<?php

use App\Core\Db;
use App\Modules\Admin\Activity;

// The activity log (PLAN.md D-052). adminSite(), adminPost() and createPage() come from
// pages_admin_test.php and fixtures.php.

/**
 * What the log says, newest first, as "kind action subject".
 *
 * @return list<string>
 */
function logged(Db $db): array
{
    return array_map(
        static fn (array $row): string => trim("{$row['kind']} {$row['action']} {$row['subject']}"),
        Activity::recent($db, 50),
    );
}

testBothDrivers('publishing, editing and deleting a page each leave a line, under the name it had', function (string $driver) {
    $db = adminSite($driver);
    $id = createPage($db, 'en', 'about', 'About', false);

    adminPost("/admin/pages/{$id}/status", ['status' => 'published']);
    adminPost("/admin/pages/{$id}/status", ['status' => 'draft']);
    adminPost("/admin/pages/{$id}/delete", []);

    assertEquals(['page deleted About', 'page unpublished About', 'page published About'], logged($db), 'the log');
    $row = Activity::recent($db, 1)[0];
    assertEquals(null, Activity::link($row['kind'], $row['action'], $row['subjectId']), 'a deleted page is not linked');
    assertEquals('/admin/pages/' . $id, Activity::link('page', 'published', $id), 'a live one is');
});

testBothDrivers('menus, settings and maintenance are logged too', function (string $driver) {
    $db = adminSite($driver);
    adminPost('/admin/menus', ['name' => 'Header', 'locale' => 'en']);
    $menu = (int) ($db->one('SELECT id FROM menus')['id'] ?? 0);
    adminPost("/admin/menus/{$menu}/items", ['url' => '/contact', 'label' => 'Contact']);
    adminPost('/admin/settings', ['site_name' => 'Northwind', 'timezone' => 'Europe/Zagreb']);
    adminPost('/admin/maintenance', ['state' => 'on', 'return' => 'settings']);
    adminPost('/admin/maintenance', ['state' => 'off', 'return' => 'settings']);

    assertEquals([
        'settings maintenance_off',
        'settings maintenance_on',
        'settings saved',
        'menu edited Header',
        'menu created Header',
    ], logged($db), 'the log');
});

testBothDrivers('a refused change is not logged', function (string $driver) {
    $db = adminSite($driver);
    adminPost('/admin/settings', ['site_name' => 'Northwind', 'timezone' => 'Not/AZone']);
    adminPost('/admin/menus', ['name' => '', 'locale' => 'en']);

    assertEquals([], logged($db), 'the log');
});

testBothDrivers('each kind and action reads as words, never as its key', function (string $driver) {
    $db = adminSite($driver);
    Activity::record($db, 'page', 'published', 3, 'About');
    Activity::record($db, 'message', 'received', 2, 'Contact');

    assertEquals('Published “About”', Activity::describe('page', 'published', 'About'), 'a page');
    $screen = dispatch('/admin/activity')->body;
    assertContains(e('A message came in through “Contact”'), $screen, 'a message');
    assertContains('href="/admin/forms/2/messages"', $screen, 'linked to its form\'s messages');
    assertTrue(preg_match('~activity\.[a-z]+\.[a-z_]+~', $screen) !== 1, 'a key shown as it is');
});

test('the time reads as the time today, yesterday, days ago, then a date', function () {
    $now = new DateTimeImmutable('2026-09-19 12:00:00', new DateTimeZone('UTC'));
    assertEquals('11:30', Activity::when('2026-09-19 09:30:00', 'Europe/Zagreb', $now), 'today, in Zagreb');
    assertEquals(t('activity.yesterday'), Activity::when('2026-09-18 09:30:00', 'Europe/Zagreb', $now), 'yesterday');
    assertEquals(t('activity.days_ago', ['days' => '3']), Activity::when('2026-09-16 09:30:00', 'Europe/Zagreb', $now), 'three days ago');
    assertEquals('2 Sep', Activity::when('2026-09-02 09:30:00', 'Europe/Zagreb', $now), 'further back');
    assertEquals('00:30', Activity::when('2026-09-18 22:30:00', 'Europe/Zagreb', $now), 'late last night in UTC is today in Zagreb');
});

testBothDrivers('the full log pages by fifty, and a year is kept', function (string $driver) {
    $db = adminSite($driver);
    $db->query("INSERT INTO activity (occurred_at, kind, action, subject_id, subject) VALUES ('2024-01-01 10:00:00', 'page', 'saved', NULL, 'Ancient')");
    for ($n = 1; $n <= 55; $n++) {
        Activity::record($db, 'page', 'saved', null, "Page {$n}");
    }

    assertEquals(55, Activity::count($db), 'rows, the ancient one pruned');
    $first = dispatch('/admin/activity')->body;
    assertContains(e('Edited “Page 55”'), $first, 'the newest on the first page');
    assertTrue(!str_contains($first, e('Edited “Page 5”')), 'the oldest on the first page');
    assertContains('?page=2', $first, 'a way to the next');
    assertContains(e('Edited “Page 5”'), dispatch('/admin/activity?page=2')->body, 'the second page');
    assertEquals(200, dispatch('/admin/activity?page=99')->status, 'past the end');
});

test('a log line that cannot be written never fails the change it records', function () {
    $db = installedSite(['en' => 'English']);
    $db->query('DROP TABLE activity');
    $logged = ini_set('error_log', tmpPath('activity-error.log'));
    Activity::record($db, 'page', 'saved', 1, 'About');
    ini_set('error_log', (string) $logged);

    assertContains('Activity log:', (string) @file_get_contents(tmpPath('activity-error.log')), 'the failure is logged');
});
