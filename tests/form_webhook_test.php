<?php

use App\Core\Container;
use App\Core\Response;
use App\Modules\Forms\Form;
use App\Modules\Forms\FormState;
use App\Modules\Forms\FormToken;
use App\Modules\Forms\FormWebhook;
use App\Support\Secret;

// A form's messages sent on to a web address (PLAN.md O-77). The address is never called:
// form_webhook is the test's own, which records what it was given and answers as told.
// formSite() and storedMessages() come from form_submit_test.php.

/** The test's web address: what it was sent, and how it answers next — a status, or a throw. */
final class FakeWebhook
{
    /** @var list<array{string, list<string>, string}> */
    public array $calls = [];
    public int|Throwable $answer = 200;

    public function install(Container $container): void
    {
        $container->set('form_webhook', fn () => function (string $url, array $headers, string $body): array {
            $this->calls[] = [$url, array_values(array_map('strval', $headers)), $body];
            if ($this->answer instanceof Throwable) {
                throw $this->answer;
            }

            return [$this->answer, 'ok'];
        });
    }

    /** @return array{string, list<string>, string} */
    public function call(int $at): array
    {
        return $this->calls[$at < 0 ? count($this->calls) + $at : $at] ?? ['', [], ''];
    }
}

/**
 * A contact form sending on to https://hooks.example.com/in, signed with whsec_test, and a
 * way to send it whose web address answers with $answer (a status, or a Throwable thrown).
 *
 * @return array{db: App\Core\Db, form: int, page: int, send: Closure, web: FakeWebhook, hook: Closure}
 */
function webhookSite(string $driver, bool $keep): array
{
    $site = formSite($driver, 'en', $keep);
    // Logged in as the owner, for the admin's screens and Send again.
    $_SESSION['admin_id'] = (int) ($site['db']->one('SELECT id FROM admin')['id'] ?? 0);
    $made = Form::find($site['db'], $site['form']) ?? fail('no form');
    // Sends nowhere else: no mail is set up, so the address is where a message goes.
    Form::update($site['db'], $site['form'], $made['name'], $made['fields'], ['webhook' => 'https://hooks.example.com/in', 'webhook_secret' => Secret::seal('whsec_test', FORM_KEY), 'notify' => false] + $made['settings']);
    $web = new FakeWebhook();
    $hook = static function (Container $container) use ($web): void {
        $web->install($container);
    };
    $send = static function (array $fields) use ($site, $hook): Response {
        FormState::clear();

        return dispatch("/form/{$site['form']}", null, 'POST', [
            'page' => (string) $site['page'],
            'started' => FormToken::issue($site['form'], FORM_KEY, time() - 5),
            'website' => '',
            'f' => $fields,
        ], '198.51.100.7', $hook);
    };

    return ['db' => $site['db'], 'form' => $site['form'], 'page' => $site['page'], 'send' => $send, 'web' => $web, 'hook' => $hook];
}

test('an address is https on the public internet, or it is not saved', function () {
    assertEquals('', FormWebhook::invalid(''), 'none');
    assertEquals('', FormWebhook::invalid('https://hooks.zapier.com/hooks/catch/1/abc/'), 'a public address');
    assertEquals(t('address.https'), FormWebhook::invalid('http://hooks.example.com/in'), 'plain http');
    assertEquals(t('address.https'), FormWebhook::invalid('https://user:pass@hooks.example.com/in'), 'a password in the address');
    assertEquals(t('address.https'), FormWebhook::invalid('hooks.example.com/in'), 'no scheme');
    foreach (['https://localhost/in', 'https://127.0.0.1/in', 'https://10.0.0.5/in', 'https://192.168.1.2/in', 'https://169.254.169.254/latest', 'https://[::1]/in', 'https://printer.local/in'] as $private) {
        assertEquals(t('address.private'), FormWebhook::invalid($private), $private);
    }
});

test('a name that resolves to a private address is not called', function () {
    $refused = static function (Closure $resolve): string {
        try {
            FormWebhook::post('https://hooks.example.com/in', [], '{}', $resolve);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return 'called';
    };
    assertEquals(t('address.private'), $refused(static fn (): array => ['10.1.2.3']), 'a private address');
    assertEquals(t('address.private'), $refused(static fn (): array => ['93.184.215.14', '127.0.0.1']), 'one private among public ones');
    assertEquals(t('address.unknown_host', ['host' => 'hooks.example.com']), $refused(static fn (): array => []), 'a name that resolves to nothing');
});

testBothDrivers('each message is sent on, signed, and a form that keeps nothing keeps nothing', function (string $driver) {
    $site = webhookSite($driver, false);
    $response = ($site['send'])(['name' => 'Ana', 'email' => 'ana@example.org', 'message' => 'Hello there']);
    assertEquals(303, $response->status, 'thanked: the address took it');
    assertEquals([], storedMessages($site['db']), 'nothing kept');

    [$url, $headers, $body] = $site['web']->call(0);
    assertEquals('https://hooks.example.com/in', $url, 'the address');
    assertTrue(in_array('X-Boxlet-Signature: sha256=' . hash_hmac('sha256', $body, 'whsec_test'), $headers, true), 'signed with the form\'s secret');
    assertTrue(in_array('X-Boxlet-Event: form.submitted', $headers, true), 'the event');
    $sent = json_decode($body, true);
    assertEquals(['id' => $site['form'], 'name' => 'Contact', 'language' => 'en'], $sent['form'] ?? null, 'the form');
    assertEquals(['name' => 'Ana', 'email' => 'ana@example.org', 'message' => 'Hello there'], $sent['values'] ?? null, 'the answers by key');
    assertEquals('Name', $sent['fields'][0]['label'] ?? null, 'and with their labels');
    assertEquals($site['page'], $sent['page']['id'] ?? null, 'the page it came from');
    assertTrue(str_ends_with((string) ($sent['page']['url'] ?? ''), '/contact'), 'and its address');
    assertTrue(preg_match('~^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$~', (string) ($sent['submitted_at'] ?? '')) === 1, 'the time, in UTC');
});

testBothDrivers('a message the address does not take is kept to be sent again, and Send again sends it', function (string $driver) {
    $site = webhookSite($driver, false);
    $site['web']->answer = new RuntimeException('hooks.example.com could not be reached: timed out');
    assertEquals(303, ($site['send'])(['name' => 'Ana', 'email' => 'ana@example.org', 'message' => 'Hello'])->status, 'thanked: it is not lost');
    $site['web']->answer = 500;
    ($site['send'])(['name' => 'Ivo', 'email' => 'ivo@example.org', 'message' => 'Hi']);

    $kept = storedMessages($site['db']);
    assertEquals(2, count($kept), 'both kept, though the form keeps nothing');
    assertEquals('hooks.example.com could not be reached: timed out', $kept[0]['webhook_error'] ?? null, 'why');
    assertEquals(t('forms.webhook_status', ['status' => '500']), $kept[1]['webhook_error'] ?? null, 'a refusal by its status');
    $list = dispatch("/admin/forms/{$site['form']}/messages")->body;
    assertContains(e(t('messages.undelivered')), $list, 'marked on the list');
    assertContains("/admin/forms/{$site['form']}/messages/{$kept[0]['id']}/resend", $list, 'with Send again');
    assertContains('webhook_failed', (string) ($site['db']->one("SELECT action FROM activity WHERE action = 'webhook_failed'")['action'] ?? ''), 'in the activity log');

    $resend = static fn (int $id): Response => dispatch("/admin/forms/{$site['form']}/messages/{$id}/resend", null, 'POST', ['_csrf' => (new App\Core\Session())->csrfToken()], '203.0.113.10', $site['hook']);
    $site['web']->answer = 502;
    $resend((int) $kept[0]['id']);
    assertEquals(t('forms.webhook_status', ['status' => '502']), storedMessages($site['db'])[0]['webhook_error'] ?? null, 'refused again: the new reason');
    $site['web']->answer = 200;
    assertRedirectedTo("/admin/forms/{$site['form']}/messages", $resend((int) $kept[0]['id']));
    assertEquals(t('messages.resent_gone'), $_SESSION['flash'] ?? null, 'said');
    assertEquals([(int) $kept[1]['id']], array_map(static fn (array $m): int => (int) $m['id'], storedMessages($site['db'])), 'taken, and no longer kept where the form keeps nothing');
    $again = json_decode($site['web']->call(-1)[2], true);
    assertEquals('Ana', $again['values']['name'] ?? null, 'sent as it was');
});

testBothDrivers('where the form keeps its messages, one sent again stays, marked delivered', function (string $driver) {
    $site = webhookSite($driver, true);
    $site['web']->answer = 503;
    ($site['send'])(['name' => 'Ana', 'email' => 'ana@example.org', 'message' => 'Hello']);
    $kept = storedMessages($site['db']);
    assertEquals(1, count($kept), 'kept once, not twice');
    assertTrue($kept[0]['webhook_error'] !== null, 'marked');
    $site['web']->answer = 204;
    dispatch("/admin/forms/{$site['form']}/messages/{$kept[0]['id']}/resend", null, 'POST', ['_csrf' => (new App\Core\Session())->csrfToken()], '203.0.113.10', $site['hook']);
    $after = storedMessages($site['db']);
    assertEquals([null], array_column($after, 'webhook_error'), 'kept, and delivered');
});

testBothDrivers('a message kept only to be sent again goes after thirty days', function (string $driver) {
    $site = webhookSite($driver, false);
    $site['web']->answer = 500;
    ($site['send'])(['name' => 'Ana', 'email' => 'ana@example.org', 'message' => 'Hello']);
    $id = (int) (storedMessages($site['db'])[0]['id'] ?? 0);
    $site['db']->query('UPDATE form_submissions SET created_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 31 * 86400), $id]);
    dispatch("/admin/forms/{$site['form']}/messages");
    assertEquals([], storedMessages($site['db']), 'gone');
});

testBothDrivers('the form screen saves an address, makes its secret once, and sends a test', function (string $driver) {
    $site = formSite($driver, 'en', false);
    $_SESSION['admin_id'] = (int) ($site['db']->one('SELECT id FROM admin')['id'] ?? 0);
    $form = Form::find($site['db'], $site['form']) ?? fail('no form');
    $post = static fn (string $url): Response => adminPost("/admin/forms/{$site['form']}", [
        'action' => 'save', 'name' => 'Contact',
        'fields' => array_map(static fn (array $f): array => ['key' => $f['key'], 'type' => $f['type'], 'label' => $f['label'], 'required' => $f['required'] ? '1' : ''], $form['fields']),
        'settings' => ['webhook' => $url],
    ]);

    $refused = $post('https://192.168.1.10/hook');
    assertEquals(422, $refused->status, 'a private address refused');
    assertContains(e(t('address.private')), $refused->body, 'saying why');
    assertRedirectedTo("/admin/forms/{$site['form']}", $post('https://hooks.example.com/in'));
    $saved = Form::find($site['db'], $site['form'])['settings'];
    assertEquals('https://hooks.example.com/in', $saved['webhook'], 'the address, enough on its own for a message to reach the owner');
    $secret = Secret::open($saved['webhook_secret'], FORM_KEY);
    $json = (string) ($site['db']->one('SELECT settings_json FROM forms WHERE id = ?', [$site['form']])['settings_json'] ?? '');
    assertTrue(str_starts_with($secret, 'whsec_') && !str_contains($json, $secret), 'a secret, stored sealed');
    $post('https://hooks.example.com/other');
    assertEquals($secret, Secret::open(Form::find($site['db'], $site['form'])['settings']['webhook_secret'], FORM_KEY), 'kept when the address changes');
    assertContains('value="' . $secret . '"', dispatch("/admin/forms/{$site['form']}")->body, 'shown, to be given to the receiver');

    $web = new FakeWebhook();
    dispatch("/admin/forms/{$site['form']}/webhook-test", null, 'POST', ['_csrf' => (new App\Core\Session())->csrfToken()], '203.0.113.10', static function (Container $c) use ($web): void {
        $web->install($c);
    });
    assertEquals(t('forms.webhook_test_sent'), $_SESSION['flash'] ?? null, 'the test, taken');
    assertTrue(in_array('X-Boxlet-Event: form.test', $web->call(0)[1], true), 'marked as a test');
    assertEquals('https://hooks.example.com/other', $web->call(0)[0], 'to the saved address');
});
