<?php

use App\Core\Container;
use App\Modules\Forms\Form;
use App\Modules\Forms\FormState;
use App\Modules\Forms\FormToken;
use App\Modules\Mailer\MailSettings;

// A visitor sending a form (PLAN.md D-046, SPEC §6 and §8 Slice 7). Asserted on what the
// visitor is served and what is stored. CapturingTransport comes from fixtures.php.

const FORM_KEY = 'test-key-not-a-secret';

/**
 * A site with a contact form and a published page showing it, plus a way to send it as a
 * visitor would: the fields, the token of a page drawn five seconds ago, an empty honeypot.
 *
 * The form KEEPS its messages unless told not to: the tests of what is stored, read and sent
 * were written when every form kept them, and keeping is the owner's choice since D-269 (a new
 * form keeps none). The tests of a form that keeps none pass $keep = false.
 *
 * @return array{db: App\Core\Db, form: int, page: int, send: Closure, mail: CapturingTransport}
 */
function formSite(string $driver, string $locale = 'en', bool $keep = true): array
{
    $db = installedSite(['en' => 'English', 'hr' => 'Hrvatski'], $driver);
    createAdmin($db, 'owner@example.com', 'correct horse battery staple');
    $form = Form::create($db, $locale, 'Contact');
    $made = Form::find($db, $form) ?? fail('no form');
    Form::update($db, $form, $made['name'], $made['fields'], ['keep' => $keep] + $made['settings']);
    $page = createPage($db, $locale, 'contact', 'Contact', true, [
        ['type' => 'form', 'content' => ['heading' => 'Write to us', 'form' => $form]],
    ]);
    $mail = new CapturingTransport();
    $send = static function (array $fields, array $extra = [], string $ip = '198.51.100.7') use ($form, $page, $mail): App\Core\Response {
        FormState::clear();

        return dispatch("/form/{$form}", null, 'POST', $extra + [
            'page' => (string) $page,
            'started' => FormToken::issue($form, FORM_KEY, time() - 5),
            'website' => '',
            'f' => $fields,
        ], $ip, static function (Container $container) use ($mail): void {
            $container->set('mail_transport', static fn () => $mail);
        });
    };

    return ['db' => $db, 'form' => $form, 'page' => $page, 'send' => $send, 'mail' => $mail];
}

/** @return list<array<string, mixed>> */
function storedMessages(App\Core\Db $db): array
{
    return array_values($db->all('SELECT * FROM form_submissions ORDER BY id'));
}

testBothDrivers('the page draws the form, and a send is stored and answered with the thank-you', function (string $driver) {
    $site = formSite($driver);
    $body = dispatch('/contact')->body;
    assertContains('action="/form/' . $site['form'] . '#form-' . $site['form'] . '"', $body, 'the form');
    assertContains('name="f[email]"', $body, 'a field');
    assertContains('name="website"', $body, 'the honeypot');

    $response = ($site['send'])(['name' => 'Ana', 'email' => 'ana@example.org', 'message' => 'Hello there']);
    assertEquals(303, $response->status, 'status');
    assertEquals('/contact?sent=' . $site['form'] . '#form-' . $site['form'], $response->headers['Location'] ?? null, 'back to the page');

    $stored = storedMessages($site['db']);
    assertEquals(1, count($stored), 'messages stored');
    $answers = json_decode((string) $stored[0]['data_json'], true);
    assertEquals(['Name', 'Ana'], [$answers[0]['label'] ?? null, $answers[0]['value'] ?? null], 'an answer kept with its label');
    assertEquals($site['page'], (int) $stored[0]['page_id'], 'where it was sent from');
    assertTrue($stored[0]['ip_hash'] !== '198.51.100.7' && strlen((string) $stored[0]['ip_hash']) === 64, 'the address is stored raw');

    assertContains(e(site_t('site.form.thanks', 'en')), dispatch('/contact?sent=' . $site['form'])->body, 'the thank-you');
});

testBothDrivers('a refused send draws the page again, with what was typed and each problem beside its field', function (string $driver) {
    $site = formSite($driver);
    $response = ($site['send'])(['name' => 'Ana', 'email' => 'not-an-address', 'message' => '']);

    assertEquals(422, $response->status, 'status');
    assertContains('value="Ana"', $response->body, 'what was typed');
    assertContains(e(site_t('site.form.email', 'en')), $response->body, 'the email problem');
    assertContains(e(site_t('site.form.required', 'en')), $response->body, 'the missing message');
    assertContains('aria-invalid="true"', $response->body, 'marked for a screen reader');
    assertEquals([], storedMessages($site['db']), 'a refused send was stored');
});

testBothDrivers('a script is thanked and ignored: honeypot filled, sent too fast, or a token it made up', function (string $driver) {
    $site = formSite($driver);
    $fields = ['name' => 'Bot', 'email' => 'bot@example.org', 'message' => 'Buy now'];

    assertEquals(303, (($site['send'])($fields, ['website' => 'http://spam.example']))->status, 'honeypot');
    assertEquals(303, (($site['send'])($fields, ['started' => FormToken::issue($site['form'], FORM_KEY)]))->status, 'sent at once');
    assertEquals(303, (($site['send'])($fields, ['started' => (time() - 60) . '.' . str_repeat('a', 64)]))->status, 'a made-up token');
    assertEquals(303, (($site['send'])($fields, ['started' => FormToken::issue($site['form'] + 1, FORM_KEY, time() - 60)]))->status, 'another form\'s token');
    assertEquals([], storedMessages($site['db']), 'spam was stored');
});

testBothDrivers('one sender may send five messages in ten minutes, and is told when that is used up', function (string $driver) {
    $site = formSite($driver);
    $fields = ['name' => 'Ana', 'email' => 'ana@example.org', 'message' => 'Hello'];
    for ($n = 0; $n < 5; $n++) {
        ($site['send'])($fields);
    }
    $sixth = ($site['send'])($fields);
    assertEquals(429, $sixth->status, 'the sixth');
    assertContains(e(site_t('site.form.too_many', 'en')), $sixth->body, 'what the visitor is told');
    assertEquals(5, count(storedMessages($site['db'])), 'messages stored');

    // Another sender is not held back by the first.
    assertEquals(303, (($site['send'])($fields, [], '198.51.100.99'))->status, 'another sender');
});

testBothDrivers('the owner is emailed each message, and the sender gets the reply the owner wrote', function (string $driver) {
    $site = formSite($driver);
    MailSettings::save($site['db'], ['transport' => 'sendmail', 'from_address' => 'hello@example.com'], FORM_KEY);
    $form = Form::find($site['db'], $site['form']) ?? fail('no form');
    $settings = ['autoreply' => true, 'autoreply_subject' => 'We got it', 'autoreply_body' => 'Thanks, we will write back soon.'] + $form['settings'];
    Form::update($site['db'], $site['form'], $form['name'], $form['fields'], $settings);

    ($site['send'])(['name' => 'Ana', 'email' => 'ana@example.org', 'message' => 'Hello there']);
    assertEquals(2, count($site['mail']->sent), 'messages sent');
    [$notice, $reply] = $site['mail']->sent;
    assertEquals('owner@example.com', $notice->getTo()[0]->getAddress(), 'the notification, to the owner');
    assertEquals('ana@example.org', $notice->getReplyTo()[0]->getAddress(), 'replying to it writes to the visitor');
    assertContains("Message:\nHello there", (string) $notice->getTextBody(), 'the answers in it');
    assertEquals('ana@example.org', $reply->getTo()[0]->getAddress(), 'the reply, to the visitor');
    assertEquals('We got it', $reply->getSubject(), 'its subject');
    assertEquals('Thanks, we will write back soon.', $reply->getTextBody(), 'its words');
});

testBothDrivers('with no way of sending chosen the message is still kept, and nothing is sent', function (string $driver) {
    $site = formSite($driver);
    ($site['send'])(['name' => 'Ana', 'email' => 'ana@example.org', 'message' => 'Hello']);

    assertEquals(1, count(storedMessages($site['db'])), 'the message');
    assertEquals([], $site['mail']->sent, 'mail');
});

testBothDrivers('a form speaks its page\'s language, and only on a page of that language', function (string $driver) {
    $site = formSite($driver, 'hr');
    $body = dispatch('/hr/contact')->body;
    assertContains('>' . site_t('site.form.send', 'hr') . '</button>', $body, 'the button in Croatian');
    assertContains('>Ime', $body, 'a label in Croatian');

    // The same form placed on an English page draws nothing: it is Croatian.
    createPage($site['db'], 'en', 'write', 'Write', true, [['type' => 'form', 'content' => ['heading' => 'Write to us', 'form' => $site['form']]]]);
    assertTrue(!str_contains(dispatch('/write')->body, '<form class="site-form"'), 'a Croatian form on an English page');
});

// Guard (source, not behaviour): the session CSRF check is waived for exactly the routes named
// here. Every other POST still meets it; another visitorPost() is a decision, not a convenience.
// The second was decided in PLAN.md O-78: /mcp, which no browser session reaches and which is
// guarded by its key, its Origin check and its limit (McpController; mcp_test).
test('only the form route and the MCP endpoint are sent without the session\'s CSRF token', function () {
    $bootstrap = (string) file_get_contents(dirname(__DIR__) . '/app/bootstrap.php');
    preg_match_all('~->visitorPost\(\'([^\']+)\'~', $bootstrap, $routes);

    assertEquals(['/form/{id:\d+}', '/mcp'], $routes[1], 'routes exempt from the CSRF check');
    // And an admin POST without a token is still refused.
    adminSite('sqlite');
    assertEquals(403, dispatch('/admin/forms', null, 'POST', ['name' => 'x', 'locale' => 'en'])->status, 'an admin POST without a token');
});

testBothDrivers('a page with a form is never kept, so each visitor gets a token of their own (O-64)', function (string $driver) {
    $site = formSite($driver);
    createPage($site['db'], 'en', 'about', 'About', true, [['type' => 'text', 'content' => ['heading' => 'About', 'body' => '<p>Words.</p>']]]);

    // The token is the time the page was drawn: kept, every visitor had the same one, and a
    // script sending at once was "older" than three seconds (measured on boxletcms.org).
    $form = dispatch('/contact');
    assertContains('name="started"', $form->body, 'the form is drawn');
    assertEquals('no-store', $form->headers['Cache-Control'] ?? null, 'a page with a form, not to be kept');
    $plain = dispatch('/about');
    assertTrue(!isset($plain->headers['Cache-Control']), 'a page without one is kept as before');
});

testBothDrivers('a new form keeps nothing a visitor writes: the message is only emailed, and the limit still holds', function (string $driver) {
    $site = formSite($driver, 'en', false);
    assertEquals(false, (Form::find($site['db'], Form::create($site['db'], 'en', 'Another')) ?? fail('no form'))['settings']['keep'], 'a new form keeps nothing');
    MailSettings::save($site['db'], ['transport' => 'sendmail', 'from_address' => 'hello@example.com'], FORM_KEY);
    $fields = ['name' => 'Ana', 'email' => 'ana@example.org', 'message' => 'Hello'];

    assertEquals(303, (($site['send'])($fields))->status, 'thanked');
    assertEquals([], storedMessages($site['db']), 'nothing of it stored');
    $notice = $site['mail']->sent[0] ?? fail('no mail');
    assertContains("Message:\nHello", (string) $notice->getTextBody(), 'the email carries it');
    assertContains(t('forms.mail.not_kept'), (string) $notice->getTextBody(), 'and says it is the only copy');
    assertTrue(!str_contains((string) $notice->getTextBody(), '/messages'), 'with no link to messages there are none of');
    $sends = $site['db']->all('SELECT * FROM form_sends');
    assertEquals(1, count($sends), 'the send counted');
    assertEquals(['id', 'form_id', 'ip_hash', 'created_at'], array_keys($sends[0]), 'as a hash and a time, nothing written');
    assertTrue(!str_contains((string) json_encode($sends), '198.51.100.7'), 'never the address');

    for ($n = 0; $n < 4; $n++) {
        ($site['send'])($fields);
    }
    assertEquals(429, (($site['send'])($fields))->status, 'the sixth refused, with nothing kept to count');
});

testBothDrivers('a form that keeps nothing and cannot email asks the visitor to try again, and keeps what they typed', function (string $driver) {
    $site = formSite($driver, 'en', false);
    $response = ($site['send'])(['name' => 'Ana', 'email' => 'ana@example.org', 'message' => 'Hello']);
    assertEquals(503, $response->status, 'not thanked');
    assertContains(e(site_t('site.form.not_sent', 'en')), $response->body, 'told why');
    assertContains('ana@example.org', $response->body, 'what they typed, still there');
    assertEquals([], storedMessages($site['db']), 'nothing stored');
    assertEquals(0, (int) ($site['db']->one("SELECT COUNT(*) AS n FROM activity WHERE action = 'received'")['n'] ?? 0), 'and no message said to have come');
});

testBothDrivers('a form must keep its messages or email them; the editor says when email is missing', function (string $driver) {
    $site = formSite($driver, 'en', false);
    $parsed = Form::parse([['key' => 'name', 'label' => 'Name', 'type' => 'text']], ['notify' => '', 'keep' => ''], false);
    assertEquals(t('forms.keep_or_notify'), $parsed['errors']['keep'] ?? null, 'neither refused');
    assertEquals([], Form::parse([['key' => 'name', 'label' => 'Name', 'type' => 'text']], ['keep' => '1'], false)['errors'], 'keeping alone is enough');

    $_SESSION['admin_id'] = (int) ($site['db']->one('SELECT id FROM admin')['id'] ?? 0);
    $editor = dispatch('/admin/forms/' . $site['form'])->body;
    assertContains('name="settings[keep]" value="1">', $editor, 'the choice, off');
    assertContains(e(t('forms.mail_missing')), $editor, 'and the warning: no email, nowhere for a message to go');
    assertContains(e(t('forms.not_kept')), dispatch('/admin/forms/' . $site['form'] . '/messages')->body, 'Messages says so');
});
