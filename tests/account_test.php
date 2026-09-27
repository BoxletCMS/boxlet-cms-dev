<?php

/*
 * YOUR LOGIN (PLAN.md D-132): the admin changes their own email and password from Settings,
 * each behind the current password. adminSite() logs in owner@example.com with the password
 * below; adminPost() is in pages_admin_test.php.
 */

const ACCOUNT_PASSWORD = 'correct horse battery staple';

function adminHash(App\Core\Db $db): string
{
    return (string) ($db->one('SELECT password_hash FROM admin')['password_hash'] ?? '');
}

testBothDrivers('the admin changes their password with the current one, and only then', function (string $driver) {
    $db = adminSite($driver);
    $before = adminHash($db);
    $send = static fn (array $fields) => adminPost('/admin/account/password', $fields + [
        'current_password' => ACCOUNT_PASSWORD,
        'new_password' => 'a much longer phrase now',
        'new_password_confirm' => 'a much longer phrase now',
    ]);

    foreach ([
        'a wrong current password' => [['current_password' => 'not it at all'], t('account.current_wrong')],
        'a short new one' => [['new_password' => 'short', 'new_password_confirm' => 'short'], t('install.admin.short_password', ['min' => 12])],
        'two that differ' => [['new_password_confirm' => 'a different long phrase'], t('install.admin.mismatch')],
    ] as $what => [$fields, $said]) {
        assertRedirectedTo('/admin/settings#account', $send($fields));
        assertEquals($said, $_SESSION['flash'] ?? null, $what . ': what the admin is told');
        assertEquals('error', $_SESSION['flash_kind'] ?? null, $what . ': told as a refusal');
        assertEquals($before, adminHash($db), $what . ': the password changed anyway');
    }

    assertRedirectedTo('/admin/settings#account', $send([]));
    assertEquals(t('account.password_changed'), $_SESSION['flash'] ?? null, 'the change is confirmed');
    assertTrue(password_verify('a much longer phrase now', adminHash($db)), 'the new password does not open the account');
    assertTrue(!password_verify(ACCOUNT_PASSWORD, adminHash($db)), 'the old password still does');
    assertEquals(1, (int) ($db->one("SELECT COUNT(*) AS n FROM activity WHERE kind = 'account' AND action = 'password'")['n'] ?? 0), 'the change is in the log');
});

testBothDrivers('guessing the current password here is as slow as at the login form', function (string $driver) {
    $db = adminSite($driver);
    for ($n = 0; $n < App\Modules\Auth\LoginThrottle::MAX_FAILURES; $n++) {
        adminPost('/admin/account/password', ['current_password' => 'guess ' . $n, 'new_password' => 'a much longer phrase now', 'new_password_confirm' => 'a much longer phrase now']);
    }
    // The right password, now: still refused, the account being locked for the window.
    adminPost('/admin/account/password', ['current_password' => ACCOUNT_PASSWORD, 'new_password' => 'a much longer phrase now', 'new_password_confirm' => 'a much longer phrase now']);
    assertEquals(t('auth.throttled', ['minutes' => 15]), $_SESSION['flash'] ?? null, 'the lock');
    assertTrue(password_verify(ACCOUNT_PASSWORD, adminHash($db)), 'the password changed through the lock');
});

testBothDrivers('the admin changes their login email with the current password', function (string $driver) {
    $db = adminSite($driver);
    adminPost('/admin/account/email', ['email' => 'new@example.com', 'current_password' => 'wrong']);
    assertEquals(t('account.current_wrong'), $_SESSION['flash'] ?? null, 'a wrong password');
    adminPost('/admin/account/email', ['email' => 'not an address', 'current_password' => ACCOUNT_PASSWORD]);
    assertEquals(t('account.email_invalid'), $_SESSION['flash'] ?? null, 'not an address');
    assertEquals('owner@example.com', $db->one('SELECT email FROM admin')['email'] ?? null, 'changed by a refused request');

    assertRedirectedTo('/admin/settings#account', adminPost('/admin/account/email', ['email' => '  New.Owner@Example.com ', 'current_password' => ACCOUNT_PASSWORD]));
    assertEquals('new.owner@example.com', $db->one('SELECT email FROM admin')['email'] ?? null, 'the address, trimmed and in lower case as a login reads it');

    // And the screen says so, under Your login, which the rail's foot leads to.
    $screen = dispatch('/admin/settings')->body;
    assertContains('id="account"', $screen, 'Your login');
    assertContains('value="new.owner@example.com"', $screen, 'the address shown');
    assertContains('href="/admin/settings#account"', $screen, 'the rail\'s foot leads there');
});

/*
 * A FORGOTTEN PASSWORD (D-132): a link by email, once and for an hour; a file by FTP.
 */

/** The site set to send mail, into $capture rather than anywhere. */
function accountMail(App\Core\Db $db, CapturingTransport $capture): Closure
{
    App\Modules\Mailer\MailSettings::save($db, ['transport' => 'sendmail', 'from_address' => 'hello@example.com'], (string) (TestSite::$env['APP_KEY'] ?? ''));

    return static function (App\Core\Container $container) use ($capture): void {
        $container->set('mail_transport', static fn () => $capture);
    };
}

testBothDrivers('a forgotten password is reset from a link sent by email, once, within the hour', function (string $driver) {
    $db = adminSite($driver);
    unset($_SESSION['admin_id']);
    $capture = new CapturingTransport();
    $withMail = accountMail($db, $capture);
    $csrf = (new App\Core\Session())->csrfToken();

    // An address that is not the admin's gets the same words, and no mail.
    $stranger = dispatch('/admin/forgot', null, 'POST', ['_csrf' => $csrf, 'email' => 'someone@example.org'], '203.0.113.10', $withMail);
    $owner = dispatch('/admin/forgot', null, 'POST', ['_csrf' => $csrf, 'email' => 'Owner@Example.com'], '203.0.113.11', $withMail);
    assertEquals(200, $owner->status, 'the answer');
    $said = e(t('account.forgot_sent', ['minutes' => '60']));
    assertTrue(str_contains($stranger->body, $said) && str_contains($owner->body, $said), 'the two answers differ');
    assertEquals(1, count($capture->sent), 'mails sent');

    $mail = $capture->sent[0];
    assertEquals('owner@example.com', $mail->getTo()[0]->getAddress(), 'to the admin');
    preg_match('~/admin/reset/([0-9a-f]{64})~', (string) $mail->getTextBody(), $link) || fail('no link in the mail');
    $token = $link[1];
    assertTrue(!str_contains((string) ($db->one('SELECT reset_hash FROM admin')['reset_hash'] ?? ''), $token), 'the token itself is stored');

    assertEquals(200, dispatch('/admin/reset/' . $token)->status, 'the link opens');
    $short = dispatch('/admin/reset/' . $token, null, 'POST', ['_csrf' => $csrf, 'new_password' => 'short', 'new_password_confirm' => 'short']);
    assertEquals(422, $short->status, 'a short password');
    assertRedirectedTo('/admin/login', dispatch('/admin/reset/' . $token, null, 'POST', ['_csrf' => $csrf, 'new_password' => 'a brand new long phrase', 'new_password_confirm' => 'a brand new long phrase']));
    assertTrue(password_verify('a brand new long phrase', adminHash($db)), 'the new password was not set');
    assertContains(e(t('account.reset_done')), dispatch('/admin/login')->body, 'the login form says so');

    // Spent: the same link opens nothing again.
    assertEquals(410, dispatch('/admin/reset/' . $token)->status, 'a used link');
    assertEquals(410, dispatch('/admin/reset/' . $token, null, 'POST', ['_csrf' => $csrf, 'new_password' => 'yet another long phrase', 'new_password_confirm' => 'yet another long phrase'])->status, 'a used link, posted to');

    // And an hour later a fresh one has stopped working.
    $fresh = App\Modules\Auth\PasswordReset::issue($db, 'owner@example.com', time() - 3601) ?? fail('no token');
    assertEquals(410, dispatch('/admin/reset/' . $fresh)->status, 'a link over an hour old');
});

testBothDrivers('a site that cannot send mail says so, and the FTP way sets a password from a file', function (string $driver) {
    $db = adminSite($driver);
    unset($_SESSION['admin_id']);
    $csrf = (new App\Core\Session())->csrfToken();
    $answer = dispatch('/admin/forgot', null, 'POST', ['_csrf' => $csrf, 'email' => 'owner@example.com']);
    assertContains(e(t('account.forgot_no_mail')), $answer->body, 'no way of sending mail');
    assertEquals(null, $db->one('SELECT reset_hash FROM admin')['reset_hash'] ?? null, 'a link was made that nothing can send');

    $storage = (string) (TestSite::$env['STORAGE_PATH'] ?? '');
    $file = $storage . '/' . App\Modules\Auth\PasswordReset::FILE;

    file_put_contents($file, "too short\n");
    assertContains(e(t('account.file_refused', ['file' => 'storage/reset-password', 'problem' => t('install.admin.short_password', ['min' => 12])])), dispatch('/admin/login')->body, 'a short one refused');
    assertTrue(!is_file($file), 'a refused file was left on the server');
    assertTrue(password_verify(ACCOUNT_PASSWORD, adminHash($db)), 'the password changed anyway');

    file_put_contents($file, "a password put there by FTP\r\n");
    assertContains(e(t('account.file_done', ['file' => 'storage/reset-password'])), dispatch('/admin/login')->body, 'set');
    assertTrue(!is_file($file), 'the file was left on the server');
    assertTrue(password_verify('a password put there by FTP', adminHash($db)), 'the password from the file');
});

testBothDrivers('guessing addresses at the forgotten-password form is throttled', function (string $driver) {
    $db = adminSite($driver);
    unset($_SESSION['admin_id']);
    $capture = new CapturingTransport();
    $withMail = accountMail($db, $capture);
    $csrf = (new App\Core\Session())->csrfToken();
    for ($n = 0; $n < App\Modules\Auth\LoginThrottle::MAX_FAILURES; $n++) {
        dispatch('/admin/forgot', null, 'POST', ['_csrf' => $csrf, 'email' => 'owner@example.com'], '203.0.113.20', $withMail);
    }
    $locked = dispatch('/admin/forgot', null, 'POST', ['_csrf' => $csrf, 'email' => 'owner@example.com'], '203.0.113.20', $withMail);
    assertEquals(429, $locked->status, 'the sixth ask');
    assertEquals(App\Modules\Auth\LoginThrottle::MAX_FAILURES, count($capture->sent), 'mails past the limit');
});
