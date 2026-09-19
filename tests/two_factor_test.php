<?php

use App\Core\Session;
use App\Modules\Auth\TwoFactor;
use OTPHP\TOTP;
use Psr\Clock\ClockInterface;

// Two-step login (SPEC §6, PLAN.md D-050). adminSite() logs the owner in as
// owner@example.com / "correct horse battery staple"; adminPost() and assertRedirectedTo()
// come from pages_admin_test.php.

const TWO_STEP_PASSWORD = 'correct horse battery staple';

/** The code an authenticator app shows for $secret now. */
function appCode(string $secret): string
{
    $clock = new class () implements ClockInterface {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable();
        }
    };

    return TOTP::createFromSecret($secret === '' ? 'A' : $secret, $clock)->now();
}

/**
 * Two-step login switched on through the settings, as the owner does it; returns the
 * secret and the recovery codes the screen showed.
 *
 * @return array{secret: string, codes: list<string>}
 */
function twoStepOn(): array
{
    dispatch('/admin/two-step');
    $secret = (string) ($_SESSION['totp_setup'] ?? '');
    $shown = adminPost('/admin/two-step', ['code' => appCode($secret)])->body;
    preg_match_all('~<li><code>([a-z0-9]{5}-[a-z0-9]{5})</code></li>~', $shown, $codes);

    return ['secret' => $secret, 'codes' => $codes[1]];
}

/** Logs out and back in with the password, as far as that goes. */
function passwordStep(): App\Core\Response
{
    $_SESSION = [];

    return dispatch('/admin/login', null, 'POST', [
        '_csrf' => (new Session())->csrfToken(),
        'email' => 'owner@example.com',
        'password' => TWO_STEP_PASSWORD,
    ]);
}

function codeStep(string $code): App\Core\Response
{
    return adminPost('/admin/login/code', ['code' => $code]);
}

testBothDrivers('setting it up shows a QR code, and only a code from the app switches it on', function (string $driver) {
    $db = adminSite($driver);
    $setup = dispatch('/admin/two-step')->body;
    assertContains('<svg', $setup, 'the QR code');
    $secret = (string) ($_SESSION['totp_setup'] ?? '');
    assertContains(substr($secret, 0, 4), $setup, 'the key, for an app that cannot scan');

    assertEquals(422, adminPost('/admin/two-step', ['code' => '000000'])->status, 'a wrong code');
    assertEquals(null, $db->one('SELECT totp_secret FROM admin')['totp_secret'] ?? null, 'switched on by a wrong code');

    $shown = adminPost('/admin/two-step', ['code' => appCode($secret)]);
    assertEquals(200, $shown->status, 'the right code');
    assertEquals(10, preg_match_all('~<li><code>[a-z0-9]{5}-[a-z0-9]{5}</code></li>~', $shown->body), 'recovery codes shown');
    $stored = (string) ($db->one('SELECT totp_secret, recovery_codes_json FROM admin')['totp_secret'] ?? '');
    assertTrue($stored !== '' && !str_contains($stored, $secret), 'the secret is stored as it is');
    assertContains(e(t('twofactor.on_now')), dispatch('/admin/settings')->body, 'settings say it is on');
});

testBothDrivers('with it on, the password alone does not log in, and the code does', function (string $driver) {
    adminSite($driver);
    $on = twoStepOn();

    assertRedirectedTo('/admin/login/code', passwordStep());
    assertEquals(302, dispatch('/admin')->status, 'logged in on the password alone');
    assertEquals(422, codeStep('123456')->status, 'a wrong code');
    assertRedirectedTo('/admin', codeStep(appCode($on['secret'])));
    assertEquals(200, dispatch('/admin')->status, 'not logged in after the code');
});

testBothDrivers('a recovery code logs in once, and never again', function (string $driver) {
    adminSite($driver);
    $on = twoStepOn();
    assertEquals(10, count($on['codes']), 'codes shown');

    passwordStep();
    assertRedirectedTo('/admin', codeStep(strtoupper($on['codes'][0])));
    assertContains(e(t('twofactor.recovery_used', ['left' => '9'])), dispatch('/admin')->body, 'how many are left');

    passwordStep();
    assertEquals(422, codeStep($on['codes'][0])->status, 'the same code twice');
});

testBothDrivers('guessing codes is as slow as guessing passwords', function (string $driver) {
    adminSite($driver);
    twoStepOn();
    passwordStep();
    for ($n = 0; $n < 5; $n++) {
        codeStep('000000');
    }
    assertEquals(429, codeStep('000000')->status, 'the sixth guess');
});

testBothDrivers('new recovery codes need a code from the app, and turning it off needs the password', function (string $driver) {
    $db = adminSite($driver);
    $on = twoStepOn();

    adminPost('/admin/two-step/codes', ['code' => '000000']);
    assertContains(e(t('twofactor.code_wrong')), dispatch('/admin/settings')->body, 'renewed without a code');
    $renewed = adminPost('/admin/two-step/codes', ['code' => appCode($on['secret'])])->body;
    assertEquals(10, preg_match_all('~<li><code>[a-z0-9]{5}-[a-z0-9]{5}</code></li>~', $renewed), 'new codes');

    adminPost('/admin/two-step/off', ['password' => 'wrong']);
    assertTrue((new TwoFactor($db, 'test-key-not-a-secret'))->enabled(1), 'turned off with a wrong password');
    assertRedirectedTo('/admin/settings#two-step', adminPost('/admin/two-step/off', ['password' => TWO_STEP_PASSWORD]));
    assertTrue(!(new TwoFactor($db, 'test-key-not-a-secret'))->enabled(1), 'still on');
    assertRedirectedTo('/admin', passwordStep());
});

testBothDrivers('a disable-2fa file put there over FTP switches it off at the next login, and goes', function (string $driver) {
    adminSite($driver);
    twoStepOn();
    $file = (string) (TestSite::$env['STORAGE_PATH'] ?? '') . '/' . TwoFactor::RESET_FILE;
    file_put_contents($file, '');

    assertRedirectedTo('/admin', passwordStep());
    assertTrue(!is_file($file), 'the file was left');
    assertContains(e(t('twofactor.reset_done', ['file' => 'storage/disable-2fa'])), dispatch('/admin')->body, 'what happened is said');
});
