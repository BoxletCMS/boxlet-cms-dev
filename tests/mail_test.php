<?php

use App\Modules\Mailer\BrevoTransport;
use App\Modules\Mailer\MailgunTransport;
use App\Modules\Mailer\MailSettings;
use App\Modules\Mailer\ResendTransport;
use App\Support\Secret;
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

    assertRedirectedTo('/admin/settings/mail', adminPost('/admin/settings/mail', $form));
    $stored = (string) ($db->one("SELECT value_json FROM settings WHERE `key` = 'mail_smtp_password'")['value_json'] ?? '');
    assertTrue($stored !== '' && !str_contains($stored, 'hunter2-secret'), 'the password is stored as typed');
    $screen = dispatch('/admin/settings/mail')->body;
    assertTrue(!str_contains($screen, 'hunter2-secret'), 'the password is shown on the screen');
    assertContains('id="mail_smtp_password" name="mail_smtp_password" value="" autocomplete="new-password" placeholder="••••••••"', $screen, 'a trace that a password is saved');

    // Saved again with the password field empty: the password stays.
    adminPost('/admin/settings/mail', ['mail_smtp_password' => '', 'mail_from_name' => 'Northwind Studio'] + $form);
    assertEquals($stored, (string) ($db->one("SELECT value_json FROM settings WHERE `key` = 'mail_smtp_password'")['value_json'] ?? ''), 'an empty field replaced the password');
});

testBothDrivers('a save that cannot work is refused, field by field, and keeps what was typed', function (string $driver) {
    $db = adminSite($driver);

    adminPost('/admin/settings/mail', ['mail_transport' => 'smtp', 'mail_from_address' => 'not an address', 'mail_smtp_host' => '', 'mail_smtp_port' => '99999']);
    $body = dispatch('/admin/settings/mail')->body;
    foreach (['mail.address_invalid', 'mail.smtp_host_required', 'mail.smtp_port_invalid'] as $message) {
        assertContains(e(t($message)), $body, $message);
    }
    assertContains('value="not an address"', $body, 'what was typed');
    assertEquals('', MailSettings::stored($db)['transport'], 'a refused save was written');

    adminPost('/admin/settings/mail', ['mail_transport' => 'resend', 'mail_from_address' => 'hello@example.com', 'mail_resend_key' => '']);
    assertContains(e(t('mail.resend_key_required')), dispatch('/admin/settings/mail')->body, 'Resend with no key');
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
    // A saved key leaves a trace in its empty field, never the key: its ends, or dots when
    // it is too short to show ends without showing most of it.
    assertEquals('re_…WXYZ', MailSettings::trace(App\Support\Secret::seal('re_abcdefghWXYZ', MAIL_KEY), MAIL_KEY, true), 'a key');
    assertEquals('••••••••', MailSettings::trace(App\Support\Secret::seal('re_123', MAIL_KEY), MAIL_KEY, true), 'a short key');
    assertEquals('••••••••', MailSettings::trace(App\Support\Secret::seal('hunter2-secret', MAIL_KEY), MAIL_KEY, false), 'a password');
    assertEquals('', MailSettings::trace('', MAIL_KEY, true), 'nothing saved');
    assertTrue(MailSettings::transport($db, MAIL_KEY) instanceof ResendTransport, 'resend');
    MailSettings::save($db, ['transport' => 'sendmail', 'from_address' => 'a@example.com'], MAIL_KEY);
    assertTrue(MailSettings::transport($db, MAIL_KEY) instanceof SendmailTransport, 'sendmail');
});

test('Resend is sent the message, and its own words come back when it refuses', function () {
    $calls = [];
    // The request as ApiTransport hands it to curl (O-72): URL, headers, body.
    $post = static function (string $url, array $headers, string $json) use (&$calls): array {
        $calls[] = [$url, $headers, json_decode($json, true)];

        return [200, '{"id":"abc-123"}'];
    };
    $email = (new Email())->from('Northwind <hello@example.com>')->to('ana@example.com')->replyTo('visitor@example.org')
        ->subject('Hello')->text('Plain words')->html('<p>Plain words</p>');
    $sent = (new ResendTransport('re_key', $post))->send($email);

    assertEquals('https://api.resend.com/emails', $calls[0][0] ?? null, 'the endpoint');
    assertTrue(in_array('Authorization: Bearer re_key', $calls[0][1] ?? [], true), 'the key');
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

test('Brevo is sent the message as its API takes it, and its own words come back (O-72)', function () {
    $calls = [];
    $post = static function (string $url, array $headers, string $json) use (&$calls): array {
        $calls[] = [$url, $headers, json_decode($json, true)];

        return [201, '{"messageId":"<201@brevo>"}'];
    };
    $email = (new Email())->from('Northwind <hello@example.com>')->to('ana@example.com')->replyTo('Vis Itor <visitor@example.org>')
        ->subject('Hello')->text('Plain words')->html('<p>Plain words</p>');
    $sent = (new BrevoTransport('xkeysib-1', $post))->send($email);

    assertEquals('https://api.brevo.com/v3/smtp/email', $calls[0][0] ?? null, 'the endpoint');
    assertTrue(in_array('api-key: xkeysib-1', $calls[0][1] ?? [], true), 'the key, in its header');
    assertEquals([
        'sender' => ['email' => 'hello@example.com', 'name' => 'Northwind'],
        'to' => [['email' => 'ana@example.com']],
        'replyTo' => ['email' => 'visitor@example.org', 'name' => 'Vis Itor'],
        'subject' => 'Hello',
        'textContent' => 'Plain words',
        'htmlContent' => '<p>Plain words</p>',
    ], $calls[0][2] ?? null, 'what was sent');
    assertEquals('<201@brevo>', $sent?->getMessageId(), 'Brevo\'s id');

    try {
        (new BrevoTransport('xkeysib-1', static fn (): array => [401, '{"code":"unauthorized","message":"Key not found"}']))->send($email);
        fail('a refusal was not reported');
    } catch (TransportException $e) {
        assertEquals('Brevo: Key not found', $e->getMessage(), 'the refusal');
    }
    try {
        (new BrevoTransport(''))->send($email);
        fail('no key was not reported');
    } catch (TransportException $e) {
        assertEquals(t('mail.brevo_key_required'), $e->getMessage(), 'no key, said before anything is sent');
    }
});

test('Mailgun is sent a form at its region\'s address, and a refused key says what to check (O-72)', function () {
    $calls = [];
    $post = static function (string $url, array $headers, string $body) use (&$calls): array {
        parse_str($body, $fields);
        $calls[] = [$url, $headers, $fields];

        return [200, '{"id":"<20261022@mg.example.com>","message":"Queued. Thank you."}'];
    };
    $email = (new Email())->from('Northwind <hello@mg.example.com>')->to('ana@example.com')->replyTo('visitor@example.org')
        ->subject('Hello')->text('Plain words')->html('<p>Plain words</p>');
    $sent = (new MailgunTransport('key-1', 'mg.example.com', 'eu', $post))->send($email);

    assertEquals('https://api.eu.mailgun.net/v3/mg.example.com/messages', $calls[0][0] ?? null, 'the EU endpoint, for the domain');
    assertTrue(in_array('Authorization: Basic ' . base64_encode('api:key-1'), $calls[0][1] ?? [], true), 'the key, as the password of "api"');
    assertEquals([
        'from' => '"Northwind" <hello@mg.example.com>',
        'to' => 'ana@example.com',
        'h:Reply-To' => 'visitor@example.org',
        'subject' => 'Hello',
        'text' => 'Plain words',
        'html' => '<p>Plain words</p>',
    ], $calls[0][2] ?? null, 'what was sent');
    assertEquals('<20261022@mg.example.com>', $sent?->getMessageId(), 'Mailgun\'s id');

    (new MailgunTransport('key-1', 'mg.example.com', 'us', $post))->send($email);
    assertEquals('https://api.mailgun.net/v3/mg.example.com/messages', $calls[1][0] ?? null, 'the US endpoint');

    try {
        (new MailgunTransport('key-1', 'mg.example.com', 'us', static fn (): array => [401, 'Forbidden']))->send($email);
        fail('a refusal was not reported');
    } catch (TransportException $e) {
        assertEquals('Mailgun: ' . t('mail.mailgun_forbidden'), $e->getMessage(), 'a bare Forbidden, said as what to check');
    }
});

testBothDrivers('Brevo and Mailgun are chosen in the settings, their keys sealed and kept (O-72)', function (string $driver) {
    $db = adminSite($driver);

    assertEquals(['brevo_key' => t('mail.brevo_key_required')], MailSettings::save($db, ['transport' => 'brevo', 'from_address' => 'a@example.com'], MAIL_KEY), 'Brevo with no key');
    assertEquals([], MailSettings::save($db, ['transport' => 'brevo', 'from_address' => 'a@example.com', 'brevo_key' => 'xkeysib-abcdefghij'], MAIL_KEY), 'with one');
    assertTrue(MailSettings::transport($db, MAIL_KEY) instanceof BrevoTransport, 'brevo');
    assertTrue(!str_contains((string) MailSettings::stored($db)['brevo_key'], 'xkeysib'), 'the key sealed');
    assertEquals([], MailSettings::save($db, ['transport' => 'brevo', 'from_address' => 'a@example.com', 'brevo_key' => ''], MAIL_KEY), 'an empty key keeps the saved one');

    $refused = MailSettings::save($db, ['transport' => 'mailgun', 'from_address' => 'a@example.com', 'mailgun_domain' => 'https://mg.example.com/'], MAIL_KEY);
    assertEquals(t('mail.mailgun_key_required'), $refused['mailgun_key'] ?? null, 'Mailgun with no key');
    assertEquals(t('mail.mailgun_domain_invalid'), $refused['mailgun_domain'] ?? null, 'a domain that is an address');
    assertEquals([], MailSettings::save($db, ['transport' => 'mailgun', 'from_address' => 'a@example.com', 'mailgun_key' => 'key-1', 'mailgun_domain' => 'MG.Example.com', 'mailgun_region' => 'eu'], MAIL_KEY), 'Mailgun saved');
    $stored = MailSettings::stored($db);
    assertEquals(['mg.example.com', 'eu'], [$stored['mailgun_domain'], $stored['mailgun_region']], 'the domain, as a host name, and the region');
    assertTrue(MailSettings::transport($db, MAIL_KEY) instanceof MailgunTransport, 'mailgun');
    MailSettings::save($db, ['transport' => 'mailgun', 'from_address' => 'a@example.com', 'mailgun_domain' => 'mg.example.com', 'mailgun_region' => 'mars'], MAIL_KEY);
    assertEquals('us', MailSettings::stored($db)['mailgun_region'], 'a region that is not one');

    // Through the screen's own save, whose key seals what the screen opens.
    assertRedirectedTo('/admin/settings/mail', adminPost('/admin/settings/mail', ['mail_transport' => 'brevo', 'mail_from_address' => 'a@example.com', 'mail_brevo_key' => 'xkeysib-abcdefghij']));
    $panel = dispatch('/admin/settings/mail')->body;
    foreach (['brevo', 'mailgun'] as $way) {
        assertContains('<option value="' . $way . '"', $panel, $way . ' offered');
        assertContains('data-mail-group="' . $way . '"', $panel, 'its fields');
    }
    assertContains('placeholder="xke…ghij"', $panel, 'the saved Brevo key by its ends, never whole');
    assertTrue(!str_contains($panel, 'xkeysib-abcdefghij'), 'the key on the screen');
    assertContains('list="mail-smtp-hosts"', $panel, 'the providers\' SMTP hosts offered');
});

testBothDrivers('the test message goes to the notifications address, and says so', function (string $driver) {
    $db = adminSite($driver);
    $capture = new CapturingTransport();
    $withCapture = static function (App\Core\Container $container) use ($capture): void {
        $container->set('mail_transport', static fn () => $capture);
    };

    dispatch('/admin/settings/mail/test', null, 'POST', ['_csrf' => (new App\Core\Session())->csrfToken()], '203.0.113.10', $withCapture);
    assertEquals([], $capture->sent, 'sent with no way of sending chosen');
    assertContains(e(t('mail.test_not_configured')), dispatch('/admin/settings/mail')->body, 'what it says instead');

    MailSettings::save($db, ['transport' => 'sendmail', 'from_address' => 'hello@example.com'], MAIL_KEY);
    dispatch('/admin/settings/mail/test', null, 'POST', ['_csrf' => (new App\Core\Session())->csrfToken()], '203.0.113.10', $withCapture);
    $message = $capture->sent[0] ?? fail('no test message');
    assertEquals('owner@example.com', $message->getTo()[0]->getAddress(), 'to the admin\'s own address');
    assertEquals('hello@example.com', $message->getFrom()[0]->getAddress(), 'from the address set');
    assertContains(e(t('mail.test_sent', ['address' => 'owner@example.com'])), dispatch('/admin/settings/mail')->body, 'what it says');
});
