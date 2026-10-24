<?php

use App\Core\Container;
use App\Core\Session;
use App\Modules\Forms\FormState;
use App\Modules\Forms\FormToken;

// BOXLET SETS NO COOKIE FOR A VISITOR (PLAN.md D-293). Scripts & cookies says so to the owner, as
// the reason a site needs no banner until a script of theirs does: so it is held here. A cookie
// of Boxlet's own could only come from the session, which the admin's login, theme and known
// device use; a visitor's request never asks for it, nor answers with a Set-Cookie of any kind.

testBothDrivers('a visitor\'s pages, a missing one and a form sent are answered with no cookie and no session', function (string $driver) {
    $site = formSite($driver);
    createPage($site['db'], 'en', 'about', 'About', true);
    $asked = [];
    $watch = static function (string $what) use (&$asked): Closure {
        return static function (Container $container) use ($what, &$asked): void {
            $container->set('session', static function () use ($what, &$asked): Session {
                $asked[] = $what;

                return new Session();
            });
        };
    };

    $answers = [
        'a page' => dispatch('/about', null, 'GET', [], '203.0.113.10', $watch('a page')),
        'a page with a form' => dispatch('/contact', null, 'GET', [], '203.0.113.10', $watch('a page with a form')),
        'a page that is not there' => dispatch('/no-such-page', null, 'GET', [], '203.0.113.10', $watch('a page that is not there')),
    ];
    FormState::clear();
    $answers['a form sent'] = dispatch('/form/' . $site['form'], null, 'POST', [
        'page' => (string) $site['page'],
        'started' => FormToken::issue($site['form'], FORM_KEY, time() - 5),
        'website' => '',
        'f' => ['name' => 'Ana', 'email' => 'ana@example.com', 'message' => 'Hello'],
    ], '198.51.100.7', $watch('a form sent'));

    assertEquals([200, 200, 404], array_values(array_map(static fn ($r): int => $r->status, array_slice($answers, 0, 3))), 'the pages answered');
    assertTrue(in_array($answers['a form sent']->status, [200, 303], true), 'the form taken: ' . $answers['a form sent']->status);
    foreach ($answers as $what => $response) {
        assertTrue(!isset($response->headers['Set-Cookie']), "{$what}: a cookie");
    }
    assertEquals([], $asked, 'the session asked for');

    // The control: the admin's login does ask, so the watch above sees an ask when there is one.
    dispatch('/admin/login', null, 'GET', [], '203.0.113.10', $watch('the admin\'s login'));
    assertEquals(['the admin\'s login'], $asked, 'the admin\'s login asks for the session');
});
