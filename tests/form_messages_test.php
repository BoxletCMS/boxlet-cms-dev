<?php

use App\Modules\Forms\Form;
use App\Modules\Mailer\MailSettings;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

// What visitors sent, in the admin (PLAN.md D-046). formSite() comes from
// form_submit_test.php; adminPost() from pages_admin_test.php.

/** Logs the owner in on a site formSite() made. */
function asOwner(App\Core\Db $db): void
{
    $_SESSION['admin_id'] = (int) ($db->one('SELECT id FROM admin')['id'] ?? 0);
}

testBothDrivers('the list shows each message by who sent it, new ones marked, and opening one marks it read', function (string $driver) {
    $site = formSite($driver);
    ($site['send'])(['name' => 'Ana Horvat', 'email' => 'ana@example.org', 'message' => 'Hello there']);
    asOwner($site['db']);

    $list = dispatch("/admin/forms/{$site['form']}/messages")->body;
    assertContains('Ana Horvat · ana@example.org', $list, 'who sent it');
    assertContains(e(t('messages.new')), $list, 'marked new');
    $id = (int) ($site['db']->one('SELECT id FROM form_submissions')['id'] ?? 0);

    $one = dispatch("/admin/forms/{$site['form']}/messages/{$id}")->body;
    assertContains('Hello there', $one, 'what was written');
    assertContains('href="mailto:ana@example.org"', $one, 'a reply by email');
    assertTrue(!str_contains(dispatch("/admin/forms/{$site['form']}/messages")->body, e(t('messages.new'))), 'still marked new once opened');
});

testBothDrivers('an old message keeps the label it was asked under after the form changes', function (string $driver) {
    $site = formSite($driver);
    ($site['send'])(['name' => 'Ana', 'email' => 'ana@example.org', 'message' => 'Hello']);
    $form = Form::find($site['db'], $site['form']) ?? fail('no form');
    $fields = array_map(
        static fn (array $field): array => $field['key'] === 'name' ? ['label' => 'Full name'] + $field : $field,
        $form['fields'],
    );
    Form::update($site['db'], $site['form'], $form['name'], $fields, $form['settings']);
    asOwner($site['db']);

    $id = (int) ($site['db']->one('SELECT id FROM form_submissions')['id'] ?? 0);
    $one = dispatch("/admin/forms/{$site['form']}/messages/{$id}")->body;
    assertContains('<dt>Name</dt>', $one, 'the label it was sent under');
    assertTrue(!str_contains($one, '<dt>Full name</dt>'), 'relabelled after the fact');
});

testBothDrivers('the spreadsheet has a column per field, dates in the site\'s time, and no formula a visitor wrote', function (string $driver) {
    $site = formSite($driver);
    ($site['send'])(['name' => '=HYPERLINK("http://evil.example")', 'email' => 'ana@example.org', 'message' => "Two\nlines"]);
    asOwner($site['db']);

    $response = dispatch("/admin/forms/{$site['form']}/export");
    assertEquals(200, $response->status, 'status');
    assertContains('text/csv', $response->headers['Content-Type'] ?? '', 'type');
    assertContains('attachment; filename="contact-messages.csv"', $response->headers['Content-Disposition'] ?? '', 'a download');
    assertTrue(str_starts_with($response->body, "\xEF\xBB\xBF" . 'Sent,Name,Email,Message'), 'the header row: ' . substr($response->body, 0, 60));
    assertContains("\"'=HYPERLINK(\"\"http://evil.example\"\")\"", $response->body, 'a formula kept as text');
    assertContains("\"Two\nlines\"", $response->body, 'a message of two lines, in one cell');
});

testBothDrivers('deleting a message removes it and only it', function (string $driver) {
    $site = formSite($driver);
    ($site['send'])(['name' => 'Ana', 'email' => 'ana@example.org', 'message' => 'One']);
    ($site['send'])(['name' => 'Ivo', 'email' => 'ivo@example.org', 'message' => 'Two']);
    asOwner($site['db']);
    $first = (int) ($site['db']->one('SELECT MIN(id) AS id FROM form_submissions')['id'] ?? 0);

    assertRedirectedTo("/admin/forms/{$site['form']}/messages", adminPost("/admin/forms/{$site['form']}/messages/{$first}/delete", []));
    assertEquals(['Two'], array_map(
        static fn (array $row): string => (string) (json_decode((string) $row['data_json'], true)[2]['value'] ?? ''),
        $site['db']->all('SELECT data_json FROM form_submissions'),
    ), 'what is left');
});

testBothDrivers('mail that fails keeps the message, and the owner is told on the messages screen', function (string $driver) {
    $site = formSite($driver);
    MailSettings::save($site['db'], ['transport' => 'sendmail', 'from_address' => 'hello@example.com'], FORM_KEY);
    $failing = new class () extends AbstractTransport {
        public function __toString(): string
        {
            return 'failing://';
        }

        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Connection refused by smtp.example.com');
        }
    };
    App\Modules\Forms\FormState::clear();
    $response = dispatch("/form/{$site['form']}", null, 'POST', [
        'page' => (string) $site['page'],
        'started' => App\Modules\Forms\FormToken::issue($site['form'], FORM_KEY, time() - 5),
        'website' => '',
        'f' => ['name' => 'Ana', 'email' => 'ana@example.org', 'message' => 'Hello'],
    ], '198.51.100.7', static function (App\Core\Container $container) use ($failing): void {
        $container->set('mail_transport', static fn () => $failing);
    });

    assertEquals(303, $response->status, 'the visitor is still thanked');
    assertEquals(1, (int) ($site['db']->one('SELECT COUNT(*) AS n FROM form_submissions')['n'] ?? 0), 'the message is kept');
    asOwner($site['db']);
    assertContains('Connection refused by smtp.example.com', dispatch("/admin/forms/{$site['form']}/messages")->body, 'the owner is told why');
});
