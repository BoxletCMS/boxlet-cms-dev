<?php

use App\Modules\Mailer\MailSettings;
use App\Modules\Mailer\ResendTransport;
use App\Modules\Mailer\Secret;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

// How the site sends mail (PLAN.md D-045). adminSite() and adminPost() come from
// pages_admin_test.php; CapturingTransport from fixtures.php.

const MAIL_KEY = 'test-app-key-for-sealing';

test('a secret opens with its key and with nothing else', function () {
    $sealed = Secret::seal('hunter2', MAIL_KEY);
    assertTrue(!str_contains($sealed, 'hunter2'), 'the secret is readable in what is stored');
    assertEquals('hunter2', Secret::open($sealed, MAIL_KEY), 'opened with its key');
    assertEquals('', Secret::open($sealed, 'another-key'), 'opened with another key');
    assertEquals('', Secret::open(substr($sealed, 0, -2) . 'xx', MAIL_KEY), 'a tampered value');
    assertEquals('', Secret::seal('', MAIL_KEY), 'nothing sealed is nothing stored');
});

testBothDrivers('saving stores passwords sealed, keeps them when left empty, and never shows them', function (string $driver) {
    $db = adminSite($driver);
    $form = [
        'mail_transport' => 'smtp', 'mail_from_address' => 'hello@example.com', 'mail_from_name' => 'Northwind',
        'mail_smtp_host' => 'smtp.example.com', 'mail_smtp_port' => '587', 'mail_smtp_encryption' => 'starttls',
        'mail_smtp_username' => 'hello@example.com', 'mail_smtp_password' => 'hunter2-secret',
    ];

    assertRedirectedTo('/admin/settings#mail', adminPost('/admin/settings/mail', $form));
    $stored = (string) ($db->one("SELECT value_json FROM settings WHERE `key` = 'mail_smtp_password'")['value_json'] ?? '');
    assertTrue($stored !== '' && !str_contains($stored, 'hunter2-secret'), 'the password is stored as typed');
    assertTrue(!str_contains(dispatch('/admin/settings')->body, 'hunter2-secret'), 'the password is shown on the screen');

    // Saved again with the password field empty: the password stays.
    adminPost('/admin/settings/mail', ['mail_smtp_password' => '', 'mail_from_name' => 'Northwind Studio'] + $form);
    assertEquals($stored, (string) ($db->one("SELECT value_json FROM settings WHERE `key` = 'mail_smtp_password'")['value_json'] ?? ''), 'an empty field replaced the password');
});

testBothDrivers('a save that cannot work is refused, field by field, and keeps what was typed', function (string $driver) {
    $db = adminSite($driver);

    adminPost('/admin/settings/mail', ['mail_transport' => 'smtp', 'mail_from_address' => 'not an address', 'mail_smtp_host' => '', 'mail_smtp_port' => '99999']);
    $body = dispatch('/admin/settings')->body;
    foreach (['mail.address_invalid', 'mail.smtp_host_required', 'mail.smtp_port_invalid'] as $message) {
        assertContains(e(t($message)), $body, $message);
    }
    assertContains('value="not an address"', $body, 'what was typed');
    assertEquals('', MailSettings::stored($db)['transport'], 'a refused save was written');

    adminPost('/admin/settings/mail', ['mail_transport' => 'resend', 'mail_from_address' => 'hello@example.com', 'mail_resend_key' => '']);
    assertContains(e(t('mail.resend_key_required')), dispatch('/admin/settings')->body, 'Resend with no key');
});

testBothDrivers('the transport is the way chosen, with its details', function (string $driver) {
    $db = adminSite($driver);
    assertTrue(MailSettings::transport($db, MAIL_KEY) instanceof NullTransport, 'nothing chosen');

    MailSettings::save($db, ['transport' => 'smtp', 'from_address' => 'a@example.com', 'smtp_host' => 'mail.example.com', 'smtp_encryption' => 'ssl', 'smtp_username' => 'a', 'smtp_password' => 'p'], MAIL_KEY);
    $smtp = MailSettings::transport($db, MAIL_KEY);
    assertTrue($smtp instanceof EsmtpTransport, 'smtp');
    assertEquals('p', $smtp instanceof EsmtpTransport ? $smtp->getPassword() : '', 'the password, opened');
    $stream = $smtp instanceof EsmtpTransport ? $smtp->getStream() : null;
    assertEquals(465, $stream instanceof Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream ? $stream->getPort() : 0, 'SSL goes to 465');
    assertEquals(true, $stream instanceof Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream && $stream->isTLS(), 'from the first byte');

    MailSettings::save($db, ['transport' => 'resend', 'from_address' => 'a@example.com', 'resend_key' => 're_123'], MAIL_KEY);
    assertTrue(MailSettings::transport($db, MAIL_KEY) instanceof ResendTransport, 'resend');
    MailSettings::save($db, ['transport' => 'sendmail', 'from_address' => 'a@example.com'], MAIL_KEY);
    assertTrue(MailSettings::transport($db, MAIL_KEY) instanceof SendmailTransport, 'sendmail');
});

test('Resend is sent the message, and its own words come back when it refuses', function () {
    $calls = [];
    $post = static function (string $url, string $key, string $json) use (&$calls): array {
        $calls[] = [$url, $key, json_decode($json, true)];

        return [200, '{"id":"abc-123"}'];
    };
    $email = (new Email())->from('Northwind <hello@example.com>')->to('ana@example.com')->replyTo('visitor@example.org')
        ->subject('Hello')->text('Plain words')->html('<p>Plain words</p>');
    $sent = (new ResendTransport('re_key', $post))->send($email);

    assertEquals('https://api.resend.com/emails', $calls[0][0] ?? null, 'the endpoint');
    assertEquals('re_key', $calls[0][1] ?? null, 'the key');
    assertEquals([
        'from' => '"Northwind" <hello@example.com>',
        'to' => ['ana@example.com'],
        'reply_to' => ['visitor@example.org'],
        'subject' => 'Hello',
        'text' => 'Plain words',
        'html' => '<p>Plain words</p>',
    ], $calls[0][2] ?? null, 'what was sent');
    assertEquals('abc-123', $sent?->getMessageId(), 'Resend\'s id');

    $refusing = new ResendTransport('re_key', static fn (): array => [403, '{"message":"The example.com domain is not verified."}']);
    try {
        $refusing->send($email);
        fail('a refusal was not reported');
    } catch (TransportException $e) {
        assertEquals('Resend: The example.com domain is not verified.', $e->getMessage(), 'the refusal');
    }
});

testBothDrivers('the test message goes to the notifications address, and says so', function (string $driver) {
    $db = adminSite($driver);
    $capture = new CapturingTransport();
    $withCapture = static function (App\Core\Container $container) use ($capture): void {
        $container->set('mail_transport', static fn () => $capture);
    };

    dispatch('/admin/settings/mail/test', null, 'POST', ['_csrf' => (new App\Core\Session())->csrfToken()], '203.0.113.10', $withCapture);
    assertEquals([], $capture->sent, 'sent with no way of sending chosen');
    assertContains(e(t('mail.test_not_configured')), dispatch('/admin/settings')->body, 'what it says instead');

    MailSettings::save($db, ['transport' => 'sendmail', 'from_address' => 'hello@example.com'], MAIL_KEY);
    dispatch('/admin/settings/mail/test', null, 'POST', ['_csrf' => (new App\Core\Session())->csrfToken()], '203.0.113.10', $withCapture);
    $message = $capture->sent[0] ?? fail('no test message');
    assertEquals('owner@example.com', $message->getTo()[0]->getAddress(), 'to the admin\'s own address');
    assertEquals('hello@example.com', $message->getFrom()[0]->getAddress(), 'from the address set');
    assertContains(e(t('mail.test_sent', ['address' => 'owner@example.com'])), dispatch('/admin/settings')->body, 'what it says');
});
