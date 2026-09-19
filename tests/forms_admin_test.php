<?php

use App\Modules\Forms\Form;

// Forms in the admin (PLAN.md D-046). adminSite(), adminPost() and assertRedirectedTo()
// come from pages_admin_test.php.

/**
 * A stored form, which the test expects to exist.
 *
 * @return array{id: int, locale: string, name: string, fields: list<array{key: string, type: string, label: string, required: bool, options: list<string>}>, settings: array{submit: string, success: string, notify: bool, autoreply: bool, autoreply_subject: string, autoreply_body: string}}
 */
function storedForm(App\Core\Db $db, int $id): array
{
    $form = Form::find($db, $id);
    if ($form === null) {
        fail("no form {$id}");
    }

    return $form;
}

/**
 * What the edit screen would post for a form as it is stored, so a test changes only what
 * it means to.
 *
 * @param array{id: int, locale: string, name: string, fields: list<array{key: string, type: string, label: string, required: bool, options: list<string>}>, settings: array{submit: string, success: string, notify: bool, autoreply: bool, autoreply_subject: string, autoreply_body: string}} $form
 * @return array<string, mixed>
 */
function formPost(array $form, string $action = 'save'): array
{
    $fields = [];
    foreach ($form['fields'] as $field) {
        $fields[] = [
            'key' => $field['key'],
            'label' => $field['label'],
            'type' => $field['type'],
            'required' => $field['required'] ? '1' : '',
            'options' => implode("\n", $field['options']),
        ];
    }
    $settings = $form['settings'];

    return [
        'name' => $form['name'],
        'fields' => $fields,
        'settings' => [
            'submit' => $settings['submit'],
            'success' => $settings['success'],
            'notify' => $settings['notify'] ? '1' : '',
            'autoreply' => $settings['autoreply'] ? '1' : '',
            'autoreply_subject' => $settings['autoreply_subject'],
            'autoreply_body' => $settings['autoreply_body'],
        ],
        'action' => $action,
    ];
}

/** A contact form made through the admin, and its id. */
function contactForm(App\Core\Db $db, string $locale = 'en'): int
{
    adminPost('/admin/forms', ['name' => 'Contact', 'locale' => $locale]);

    return (int) ($db->one('SELECT id FROM forms ORDER BY id DESC')['id'] ?? 0);
}

testBothDrivers('a new form starts as a contact form, and the list shows it', function (string $driver) {
    $db = adminSite($driver);
    $response = adminPost('/admin/forms', ['name' => 'Contact', 'locale' => 'hr']);
    $id = (int) ($db->one('SELECT id FROM forms')['id'] ?? 0);
    assertRedirectedTo("/admin/forms/{$id}", $response);

    $form = storedForm($db, $id);
    assertEquals('hr', $form['locale'], 'its language');
    assertEquals(['name', 'email', 'message'], array_column($form['fields'], 'key'), 'the fields it starts with');
    assertEquals(['text', 'email', 'textarea'], array_column($form['fields'], 'type'), 'their kinds');
    // Labelled in the form's language, which is what a visitor reads. This failed silently
    // at first: the labels were admin keys nobody had defined, stored as the key itself.
    assertEquals(['Ime', 'E-pošta', 'Poruka'], array_column($form['fields'], 'label'), 'their labels, in Croatian');
    assertTrue($form['settings']['notify'] && !$form['settings']['autoreply'], 'notify on, reply off');
    assertContains('>Contact</a>', dispatch('/admin/forms')->body, 'the list');

    adminPost('/admin/forms', ['name' => '', 'locale' => 'xx']);
    assertEquals(1, (int) ($db->one('SELECT COUNT(*) AS n FROM forms')['n'] ?? 0), 'a form with no name or language was made');
});

testBothDrivers('the edit screen saves labels and settings, and a key never follows its label', function (string $driver) {
    $db = adminSite($driver);
    $id = contactForm($db);
    $post = formPost(storedForm($db, $id));
    $post['fields'][0]['label'] = 'Your full name';
    $post['settings']['submit'] = 'Send it';
    $post['settings']['autoreply'] = '1';
    $post['settings']['autoreply_body'] = 'Thanks, we will write back.';

    assertRedirectedTo("/admin/forms/{$id}", adminPost("/admin/forms/{$id}", $post));
    $form = storedForm($db, $id);
    assertEquals('Your full name', $form['fields'][0]['label'], 'the label');
    assertEquals('name', $form['fields'][0]['key'], 'the key followed the label');
    assertEquals('Send it', $form['settings']['submit'], 'the button');
    assertTrue($form['settings']['autoreply'], 'the reply switched on');
    assertEquals('Thanks, we will write back.', $form['settings']['autoreply_body'], 'the reply');
});

testBothDrivers('every button saves: add, move and remove a field', function (string $driver) {
    $db = adminSite($driver);
    $id = contactForm($db);
    $keys = static fn (): array => array_column((storedForm($db, $id))['fields'], 'key');

    adminPost("/admin/forms/{$id}", formPost(storedForm($db, $id), 'add'));
    assertEquals(['name', 'email', 'message', 'new_field'], $keys(), 'after adding');

    // A label typed into the form and then Add pressed: kept, not lost to the button.
    $post = formPost(storedForm($db, $id), 'add');
    $post['fields'][3]['label'] = 'Phone';
    $post['fields'][3]['type'] = 'tel';
    adminPost("/admin/forms/{$id}", $post);
    $form = storedForm($db, $id);
    assertEquals('Phone', $form['fields'][3]['label'], 'what was typed before pressing Add');
    assertEquals('new_field_2', $form['fields'][4]['key'], 'a second new field\'s key');

    adminPost("/admin/forms/{$id}", formPost($form, 'up-3'));
    assertEquals(['name', 'email', 'new_field', 'message', 'new_field_2'], $keys(), 'after moving Phone up');
    adminPost("/admin/forms/{$id}", formPost(storedForm($db, $id), 'remove-4'));
    assertEquals(['name', 'email', 'new_field', 'message'], $keys(), 'after removing the last');
});

testBothDrivers('a field with no label, or a list with no choices, is refused and the screen keeps what was typed', function (string $driver) {
    $db = adminSite($driver);
    $id = contactForm($db);
    $post = formPost(storedForm($db, $id));
    $post['fields'][0]['label'] = '';
    $post['fields'][1]['type'] = 'select';
    $post['fields'][1]['options'] = "\n  \n";
    $post['name'] = 'Contact us';

    $response = adminPost("/admin/forms/{$id}", $post);
    assertEquals(422, $response->status, 'status');
    assertContains(e(t('forms.field.label_required')), $response->body, 'no label');
    assertContains(e(t('forms.field.options_required')), $response->body, 'no choices');
    assertContains('value="Contact us"', $response->body, 'what was typed');
    assertEquals('Contact', (storedForm($db, $id))['name'], 'a refused save was stored');
});

testBothDrivers('deleting a form deletes its messages with it', function (string $driver) {
    $db = adminSite($driver);
    $id = contactForm($db);
    $db->query("INSERT INTO form_submissions (form_id, data_json, ip_hash, created_at) VALUES (?, '[]', 'x', '2026-01-01 00:00:00')", [$id]);

    assertRedirectedTo('/admin/forms', adminPost("/admin/forms/{$id}/delete", []));
    assertEquals(null, Form::find($db, $id), 'the form');
    assertEquals(0, (int) ($db->one('SELECT COUNT(*) AS n FROM form_submissions')['n'] ?? 0), 'its messages');
});

test('a list\'s choices are one per line, blank lines dropped, and other kinds keep none', function () {
    $parsed = Form::parse([
        ['key' => 'topic', 'label' => 'Topic', 'type' => 'select', 'options' => "Design\r\n\r\n  Build  \nCare"],
        ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'options' => 'stray'],
        ['key' => 'bad key!', 'label' => 'E-mail address', 'type' => 'nonsense'],
    ], [], false);

    assertEquals([], $parsed['errors'], 'errors');
    assertEquals(['Design', 'Build', 'Care'], $parsed['fields'][0]['options'], 'the choices');
    assertEquals([], $parsed['fields'][1]['options'], 'choices on a text field');
    assertEquals('e_mail_address', $parsed['fields'][2]['key'], 'a posted key that is not a key');
    assertEquals('text', $parsed['fields'][2]['type'], 'an unknown kind');
});
