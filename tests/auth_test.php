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

function postLogin(string $email, string $password, string $ip = '203.0.113.10', string $cookie = ''): Response
{
    $fields = ['_csrf' => (new Session())->csrfToken(), 'email' => $email, 'password' => $password];
    // The browser's cookies, as the request carries them: the session's live in $_SESSION.
    $_SERVER['HTTP_COOKIE'] = $cookie;
    try {
        return dispatch('/admin/login', null, 'POST', $fields, $ip);
    } finally {
        unset($_SERVER['HTTP_COOKIE']);
    }
}

/** The known-device cookie a response set, as a browser would send it back: name=value. */
function deviceCookie(Response $response): string
{
    return explode(';', (string) ($response->headers['Set-Cookie'] ?? ''))[0];
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

testBothDrivers('a browser that logged in before is not locked out by failures for its email (O-65)', function (string $driver) {
    loginSite($driver);
    // The owner logs in once here, and the browser keeps the cookie that says so.
    $first = postLogin('owner@example.com', 'correct horse battery staple', '203.0.113.20');
    assertEquals(302, $first->status, 'the first login');
    $cookie = deviceCookie($first);
    assertContains('boxlet_device=', $cookie, 'the cookie is set');
    assertContains('HttpOnly', (string) $first->headers['Set-Cookie'], 'out of scripts\' reach');
    assertContains('Path=/admin', (string) $first->headers['Set-Cookie'], 'sent to the admin only');
    $_SESSION = [];

    // Someone else, knowing the email, fails five times from five addresses.
    for ($i = 1; $i <= 5; $i++) {
        postLogin('owner@example.com', 'a guess', "198.51.100.{$i}");
    }
    assertEquals(429, postLogin('owner@example.com', 'correct horse battery staple', '198.51.100.50')->status, 'a new browser is still locked out');
    assertEquals(429, postLogin('owner@example.com', 'correct horse battery staple', '198.51.100.51', 'boxlet_device=made.up')->status, 'a cookie made up is no cookie');
    assertEquals(302, postLogin('owner@example.com', 'correct horse battery staple', '203.0.113.21', $cookie)->status, 'the owner\'s browser gets in');

    // The limit per address still holds for that browser.
    $_SESSION = [];
    for ($i = 1; $i <= 5; $i++) {
        postLogin('owner@example.com', 'a slip', '192.0.2.40', $cookie);
    }
    assertEquals(429, postLogin('owner@example.com', 'correct horse battery staple', '192.0.2.40', $cookie)->status, 'its address, locked');
});

testBothDrivers('a login ends after hours without use, and after a week whatever (O-67)', function (string $driver) {
    loginSite($driver);
    $ended = e(t('auth.session_ended'));
    $loggedIn = static function (): void {
        $_SESSION = [];
        assertEquals(302, postLogin('owner@example.com', 'correct horse battery staple')->status, 'logged in');
    };

    // In use: each request moves the last-seen time on.
    $loggedIn();
    $_SESSION['admin_seen'] = time() - App\Modules\Auth\AdminLogin::IDLE + 60;
    assertEquals(200, dispatch('/admin')->status, 'just inside the idle limit');
    assertTrue(abs((int) $_SESSION['admin_seen'] - time()) <= 2, 'the request counted as use');

    // Idle too long: back to the login, saying why.
    $_SESSION['admin_seen'] = time() - App\Modules\Auth\AdminLogin::IDLE - 1;
    assertRedirectedTo('/admin/login', dispatch('/admin'));
    assertTrue(!isset($_SESSION['admin_id']), 'still logged in after the idle limit');
    assertContains($ended, dispatch('/admin/login')->body, 'the login says why');

    // In use all along, but begun over a week ago.
    $loggedIn();
    $_SESSION['admin_since'] = time() - App\Modules\Auth\AdminLogin::ABSOLUTE - 1;
    assertRedirectedTo('/admin/login', dispatch('/admin'));

    // A session from before these limits, with only the admin's id, carries on from now.
    $loggedIn();
    $_SESSION = ['admin_id' => $_SESSION['admin_id']];
    assertEquals(200, dispatch('/admin')->status, 'a session begun before the update');
});

testBothDrivers('changing or resetting the password ends every other login (O-67)', function (string $driver) {
    loginSite($driver);
    $_SESSION = [];
    postLogin('owner@example.com', 'correct horse battery staple');
    $elsewhere = $_SESSION;

    // Changed here: this login carries on, the other one ends.
    $changed = dispatch('/admin/account/password', null, 'POST', ['_csrf' => (new Session())->csrfToken(), 'current_password' => 'correct horse battery staple', 'new_password' => 'a much longer phrase now', 'new_password_confirm' => 'a much longer phrase now']);
    assertEquals(302, $changed->status, 'the change');
    assertEquals(200, dispatch('/admin')->status, 'the login that changed it');
    $here = $_SESSION;
    $_SESSION = $elsewhere;
    assertRedirectedTo('/admin/login', dispatch('/admin'));

    // Reset by a file over FTP: no login survives it.
    $_SESSION = $here;
    file_put_contents(tmpPath('storage') . '/reset-password', 'yet another long phrase');
    $_SESSION = [];
    dispatch('/admin/login');
    $_SESSION = $here;
    assertRedirectedTo('/admin/login', dispatch('/admin'));
});
