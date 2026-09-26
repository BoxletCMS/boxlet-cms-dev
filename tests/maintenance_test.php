<?php

use App\Modules\Update\Maintenance;

// Maintenance mode (PLAN.md D-021). One gate, two triggers: this flag and a pending
// migration. The flag is a file rather than a settings row because maintenance is exactly
// when the database may be unavailable.

function maintenanceFlag(): string
{
    return (string) (TestSite::$env['STORAGE_PATH'] ?? '') . '/maintenance.flag';
}

function turnMaintenanceOn(string $reason = Maintenance::MANUAL): void
{
    file_put_contents(maintenanceFlag(), $reason);
}

/**
 * The storage directory is a fixed path shared by every test, and the runner clears
 * $_SESSION and TestSite::$env between tests but not the files under it. A flag left
 * behind closes the site for everything that runs afterwards — which is how these tests
 * first turned eight render tests into 503s.
 *
 * The runner clears it too now. This is the same guarantee from the other side: a test
 * that switches maintenance on switches it off again itself.
 */
function turnMaintenanceOff(): void
{
    if (is_file(maintenanceFlag())) {
        unlink(maintenanceFlag());
    }
}

testBothDrivers('with maintenance on a visitor gets 503 and the admin gets the real site', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', 'about', 'About');
    turnMaintenanceOn();

    // The admin, logged in by adminSite(), still sees the page — with the bar on it. Their
    // browser sends the session cookie, and since D-128 the gate asks for it before it
    // opens the session: a request without one is a visitor's.
    $_SERVER['HTTP_COOKIE'] = 'boxlet_session=abc';
    try {
        $asAdmin = dispatch('/about');
    } finally {
        unset($_SERVER['HTTP_COOKIE']);
    }
    assertEquals(200, $asAdmin->status, 'the admin was locked out of their own site');
    assertContains('About', $asAdmin->body, 'the real page');
    assertContains('boxlet-maintenance-bar', $asAdmin->body, 'the bar saying the site is hidden');
    assertContains(t('maintenance.bar'), $asAdmin->body, 'what the bar says');

    // A visitor does not.
    unset($_SESSION['admin_id']);
    $asVisitor = dispatch('/about');
    assertEquals(503, $asVisitor->status, 'a visitor was served the site');
    assertEquals('120', $asVisitor->headers['Retry-After'] ?? null, 'Retry-After');
    assertContains(t('maintenance.public.body'), $asVisitor->body, 'the maintenance page');
    assertTrue(!str_contains($asVisitor->body, 'About'), 'the page leaked to a visitor');
    // The words differ from an update: this is the owner's choice, not a migration.
    assertTrue(!str_contains($asVisitor->body, t('update.public.body')), 'it called maintenance an update');

    turnMaintenanceOff();
});

testBothDrivers('a visitor to a closed site is given no session, so is never taken for the admin after it opens', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', 'about', 'About');
    turnMaintenanceOn();
    try {
        // The session is still logged in: only the cookie is missing, as it is from a
        // visitor's browser. Resolving the session is what would have set one (D-128).
        $opened = false;
        $response = dispatch('/about', null, 'GET', [], '203.0.113.10', function ($container) use (&$opened): void {
            $container->set('session', function () use (&$opened) {
                $opened = true;

                return new App\Core\Session();
            });
        });
        assertEquals(503, $response->status, 'a request without the cookie was served the site');
        assertEquals(false, $opened, 'a session was opened for a visitor, and with it a cookie');
    } finally {
        turnMaintenanceOff();
    }
});

testBothDrivers('switching maintenance off restores the site', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', 'about', 'About');
    turnMaintenanceOn();

    // Back to site settings, where the switch lives since D-028, not to the dashboard.
    assertRedirectedTo('/admin/settings', adminPost('/admin/maintenance', ['state' => 'off']));
    assertTrue(!is_file(maintenanceFlag()), 'the flag file is still there');

    unset($_SESSION['admin_id']);
    $response = dispatch('/about');
    assertEquals(200, $response->status, 'the site is still closed');
    assertTrue(!str_contains($response->body, 'boxlet-maintenance-bar'), 'the bar is still on the page');
});

testBothDrivers('the toggle needs a CSRF token', function (string $driver) {
    adminSite($driver);

    $refused = dispatch('/admin/maintenance', null, 'POST', ['state' => 'on']);

    assertEquals(403, $refused->status, 'a POST without a token was accepted');
    assertTrue(!is_file(maintenanceFlag()), 'it switched maintenance on without a token');
});

// The screen changed with D-028, the rule did not: wherever the switch is, it says which
// way round the site currently is and offers the other. It moved off the dashboard to sit
// beside the message visitors are shown, which is the other half of the same decision.
testBothDrivers('site settings says which way round the site is, and offers the other', function (string $driver) {
    adminSite($driver);

    // Compared against the ESCAPED text, because that is what a template renders. The
    // "on" sentence contains double quotes, so e() turns them into &quot; and a raw
    // comparison can never match — which is what failed here for several rounds while I
    // looked for the cause in the flag, the storage path and the stat cache. The "off"
    // sentence happens to contain no quotes and so matched either way: a trap left for
    // whoever writes the next assertion.
    $off = dispatch('/admin/settings')->body;
    assertContains(e(t('maintenance.off_now')), $off, 'it does not say the site is visible');
    assertContains(e(t('maintenance.turn_on')), $off, 'no way to turn it on');

    assertRedirectedTo('/admin/settings', adminPost('/admin/maintenance', ['state' => 'on']));

    // Printed rather than inferred. Four separate reproductions of this sequence outside
    // the runner have passed while this failed inside it, so the state at the moment of
    // failure is the only thing worth looking at.
    $flag = maintenanceFlag();
    $state = sprintf(
        'flag=%s exists=%s storage=%s',
        $flag,
        is_file($flag) ? 'yes' : 'NO',
        (string) (TestSite::$env['STORAGE_PATH'] ?? '(unset)'),
    );

    $on = dispatch('/admin/settings')->body;
    assertContains(e(t('maintenance.on_now')), $on, "it does not say the site is hidden — {$state}");
    assertContains(e(t('maintenance.turn_off')), $on, "no way to turn it off — {$state}");

    turnMaintenanceOff();
});

// A pending migration is the stronger trigger: the site may not render at all until it
// has run, so the admin does not get to look at it either.
testBothDrivers('a pending migration closes the site to the admin too', function (string $driver) {
    $db = adminSite($driver);
    createPage($db, 'en', 'about', 'About');

    $configure = pendingUpdate(['9001_later.sql' => "CREATE TABLE later_thing (\n    id {{pk}}\n);\n"]);

    $asAdmin = dispatchConfigured('/about', $configure);
    assertEquals(503, $asAdmin->status, 'the admin was served a site that may not render');
    assertTrue(!str_contains($asAdmin->body, 'boxlet-maintenance-bar'), 'the maintenance bar appeared during an update');
    assertContains(t('update.public.body'), $asAdmin->body, 'it called an update maintenance');

    // And the admin is sent to the screen that can fix it.
    $admin = dispatchConfigured('/admin/pages', $configure);
    assertEquals(302, $admin->status, 'status');
    assertEquals('/admin/update', $admin->headers['Location'] ?? null, 'where the admin is sent');
});

test('an automatic flag does not clear one the owner set', function () {
    $storage = tmpPath('maintenance-reasons');
    removeTree($storage);
    mkdir($storage, 0700, true);
    $maintenance = new Maintenance($storage);

    // Slice 8 will switch it on around a ZIP upload and off again afterwards. If the owner
    // had already closed the site by hand, finishing that upload must not reopen it.
    $maintenance->turnOn(Maintenance::MANUAL);
    $maintenance->turnOff(Maintenance::UPDATE);
    assertTrue($maintenance->isOn(), 'an automatic switch-off cleared the owner\'s flag');
    assertEquals(Maintenance::MANUAL, $maintenance->reason(), 'the reason changed');

    // Its own flag it may clear.
    $maintenance->turnOff();
    $maintenance->turnOn(Maintenance::UPDATE);
    assertEquals(Maintenance::UPDATE, $maintenance->reason(), 'the reason it was switched on for');
    $maintenance->turnOff(Maintenance::UPDATE);
    assertTrue(!$maintenance->isOn(), 'it could not clear its own flag');
});
