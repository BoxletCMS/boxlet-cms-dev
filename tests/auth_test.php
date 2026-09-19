<?php

use App\Core\Response;
use App\Core\Session;
use App\Modules\Auth\LoginThrottle;

// Login, rate limit, CSRF and the admin guard (SPEC §6), against both drivers.

function loginSite(string $driver): void
{
    $db = installedSite(['en' => 'English'], $driver);
    createAdmin($db, 'owner@example.com', 'correct horse battery staple');
}

function postLogin(string $email, string $password, string $ip = '203.0.113.10'): Response
{
    $fields = ['_csrf' => (new Session())->csrfToken(), 'email' => $email, 'password' => $password];

    return dispatch('/admin/login', null, 'POST', $fields, $ip);
}

testBothDrivers('login succeeds and opens the dashboard', function (string $driver) {
    loginSite($driver);
    $response = postLogin('Owner@Example.com', 'correct horse battery staple');

    assertEquals(302, $response->status, 'status');
    assertEquals('/admin', $response->headers['Location'] ?? null, 'Location header');
    assertTrue(is_int($_SESSION['admin_id'] ?? null), 'the session holds the admin id');
    $dashboard = dispatch('/admin');
    assertEquals(200, $dashboard->status, 'dashboard status');
    assertContains('<h1>' . e(t('admin.nav.dashboard')) . '</h1>', $dashboard->body, 'dashboard');
});

testBothDrivers('a wrong password and an unknown email fail with the same message', function (string $driver) {
    loginSite($driver);
    $wrongPassword = postLogin('owner@example.com', 'not the password');
    $unknownEmail = postLogin('nobody@example.com', 'not the password');

    foreach (['wrong password' => $wrongPassword, 'unknown email' => $unknownEmail] as $case => $response) {
        assertEquals(422, $response->status, "{$case}: status");
        assertContains(e(t('auth.failed')), $response->body, "{$case}: page");
    }
    assertTrue(!isset($_SESSION['admin_id']), 'no session after failed logins');
});

testBothDrivers('six wrong passwords lock the account, whether or not it exists', function (string $driver) {
    loginSite($driver);
    $locked = e(t('auth.throttled', ['minutes' => 15]));
    foreach (['owner@example.com', 'nobody@example.com'] as $email) {
        // A different IP per attempt, so only the per-account limit can trigger.
        for ($i = 1; $i <= 5; $i++) {
            assertEquals(422, postLogin($email, 'not the password', "198.51.100.{$i}")->status, "{$email} attempt {$i}");
        }
        $sixth = postLogin($email, 'correct horse battery staple', '198.51.100.99');
        assertEquals(429, $sixth->status, "{$email} sixth attempt status");
        assertContains($locked, $sixth->body, "{$email} sixth attempt page");
    }
    assertTrue(!isset($_SESSION['admin_id']), 'the right password does not get through a lock');
});

testBothDrivers('five failures from one IP lock that IP for every account', function (string $driver) {
    loginSite($driver);
    for ($i = 1; $i <= 5; $i++) {
        postLogin("guess{$i}@example.com", 'not the password', '192.0.2.1');
    }

    assertEquals(429, postLogin('owner@example.com', 'correct horse battery staple', '192.0.2.1')->status, 'same IP');
    assertEquals(302, postLogin('owner@example.com', 'correct horse battery staple', '192.0.2.2')->status, 'other IP');
});

testBothDrivers('the rate limit expires after its window and old rows are pruned', function (string $driver) {
    $db = migratedDatabase($driver);
    $throttle = new LoginThrottle($db);
    $start = 1_800_000_000;
    for ($i = 0; $i < 5; $i++) {
        $throttle->record('ip-a', 'email-a', false, $start + $i);
    }

    assertTrue($throttle->isLocked('ip-a', 'email-b', $start + 60), 'locked by IP inside the window');
    assertTrue($throttle->isLocked('ip-b', 'email-a', $start + 60), 'locked by account inside the window');
    assertTrue(!$throttle->isLocked('ip-a', 'email-a', $start + 5 + LoginThrottle::WINDOW_SECONDS), 'unlocked after the window');

    $throttle->record('ip-c', 'email-c', true, $start + 2 * LoginThrottle::WINDOW_SECONDS);
    assertEquals(1, (int) ($db->one('SELECT COUNT(*) AS n FROM login_attempts')['n'] ?? -1), 'rows left after pruning');
});

test('a POST without a valid CSRF token is rejected', function () {
    loginSite('sqlite');
    $fields = ['email' => 'owner@example.com', 'password' => 'correct horse battery staple'];

    assertEquals(403, dispatch('/admin/login', null, 'POST', $fields)->status, 'missing token');
    (new Session())->csrfToken();
    assertEquals(403, dispatch('/admin/login', null, 'POST', ['_csrf' => str_repeat('0', 64)] + $fields)->status, 'wrong token');
    assertTrue(!isset($_SESSION['admin_id']), 'not logged in');
});

test('/admin without a session redirects to the login page', function () {
    loginSite('sqlite');
    $response = dispatch('/admin');

    assertEquals(302, $response->status, 'status');
    assertEquals('/admin/login', $response->headers['Location'] ?? null, 'Location header');
});

test('logout ends the session', function () {
    loginSite('sqlite');
    postLogin('owner@example.com', 'correct horse battery staple');
    $logout = dispatch('/admin/logout', null, 'POST', ['_csrf' => (new Session())->csrfToken()]);

    assertEquals('/admin/login', $logout->headers['Location'] ?? null, 'logout redirect');
    assertEquals(302, dispatch('/admin')->status, '/admin after logout');
});

test('admin pages send a CSP that forbids inline scripts', function () {
    loginSite('sqlite');
    $csp = dispatch('/admin/login')->headers['Content-Security-Policy'] ?? '';

    assertContains("default-src 'self'", $csp, 'Content-Security-Policy');
    assertTrue(!str_contains($csp, 'unsafe-inline'), 'CSP allows unsafe-inline');
});
