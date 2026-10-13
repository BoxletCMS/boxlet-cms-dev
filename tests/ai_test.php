<?php

use App\Core\Container;
use App\Modules\Ai\AiFailure;
use App\Modules\Ai\AiQueue;
use App\Modules\Ai\AiSettings;
use App\Modules\Ai\AiTranslator;
use App\Modules\Ai\AiWork;
use App\Modules\Pages\PageDraft;
use App\Modules\Pages\PageSeo;
use App\Modules\Pages\Translations;
use App\Modules\Pages\TranslationStatus;

// AI TRANSLATION (PLAN.md D-248, the owner): Claude with the owner's own key, step by step, into
// the translation's draft. No request leaves the test: the model is a closure that answers what a
// test needs (the container's ai_ask). Helpers from pages_admin_test.php and translations_test.php.

const AI_KEY = 'sk-ant-test-0123456789abcdefghijklmnop';

/** A fake model: every value of the JSON it is given, marked as translated. */
function aiEcho(string $mark = 'HR '): Closure
{
    return static function (string $system, string $user) use ($mark): array {
        $texts = json_decode($user, true);
        $out = [];
        foreach (is_array($texts) ? $texts : [] as $n => $text) {
            $out[$n] = preg_match('~^<~', (string) $text) === 1 ? preg_replace('~>([^<]+)<~', '>' . $mark . '$1<', (string) $text) : $mark . $text;
        }

        return ['text' => json_encode($out, JSON_UNESCAPED_UNICODE), 'in' => 10, 'out' => 12];
    };
}

/** A site with a key saved, in the test site's own APP_KEY. */
function aiSite(string $driver): App\Core\Db
{
    $db = adminSite($driver);
    AiSettings::save($db, ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'model_other' => '', 'key' => AI_KEY, 'keep' => "Boxlet\nPrintworks", 'voice' => '', 'remove_key' => false], (string) TestSite::$env['APP_KEY']);

    return $db;
}

/** Every pending part of the queue run, as the step endpoint would, with $ask as the model. */
function aiRunAll(App\Core\Db $db, Closure $ask): void
{
    for ($i = 0; $i < 50 && ($next = AiQueue::next($db)) !== null; $i++) {
        try {
            AiQueue::finish($db, $next['id'], 'done', AiWork::run($db, blockRegistry(), $next['page_id'], $next['part'], ['keep' => '', 'voice' => ''], $ask));
        } catch (AiFailure $e) {
            AiQueue::finish($db, $next['id'], 'failed', $e->getMessage());
        }
    }
}

testBothDrivers('the key is sealed, shown only in part, and taken away on request', function (string $driver) {
    $db = aiSite($driver);
    $stored = AiSettings::stored($db);
    assertTrue(str_starts_with($stored['key'], 'sealed:') && !str_contains($stored['key'], AI_KEY), 'sealed, not plain');
    assertTrue(AiSettings::ready($db, (string) TestSite::$env['APP_KEY']), 'ready with a key');
    assertEquals("Boxlet\nPrintworks", $stored['keep'], 'the words to keep');

    $settings = dispatch('/admin/settings')->body;
    assertContains('id="ai"', $settings, 'the panel');
    assertTrue(!str_contains($settings, AI_KEY), 'the key never drawn back');
    assertContains('sk-…mnop', $settings, 'only its ends');

    // An empty key field keeps it; "remove" takes it away.
    adminPost('/admin/settings/ai', ['ai_provider' => 'anthropic', 'ai_model' => 'claude-haiku-4-5-20251001', 'ai_key' => '', 'ai_keep' => '', 'ai_voice' => 'Formal.']);
    assertTrue(AiSettings::ready($db, (string) TestSite::$env['APP_KEY']), 'kept by an empty field');
    assertEquals('claude-haiku-4-5-20251001', AiSettings::stored($db)['model'], 'the model');
    adminPost('/admin/settings/ai', ['ai_provider' => 'anthropic', 'ai_model' => 'other', 'ai_model_other' => 'not a model!', 'ai_key' => '']);
    assertEquals(t('ai.not_saved'), $_SESSION['flash'] ?? null, 'a model id that is not one is refused');
    adminPost('/admin/settings/ai', ['ai_provider' => 'anthropic', 'ai_model' => 'claude-sonnet-5', 'ai_key' => '', 'ai_remove_key' => '1']);
    assertTrue(!AiSettings::ready($db, (string) TestSite::$env['APP_KEY']), 'removed');
});

test('an answer is checked entry by entry: tags kept, markup cleaned, no markup in plain text', function () {
    $texts = [
        'a' => ['text' => 'Open {{snippet:hours}} daily', 'html' => false],
        'b' => ['text' => '<p>Hello <strong>you</strong></p>', 'html' => true],
        'c' => ['text' => 'Plain words', 'html' => false],
        'd' => ['text' => 'Lost tag {{year}}', 'html' => false],
        'e' => ['text' => '   ', 'html' => false],
    ];
    $answer = static fn (string $s, string $u): array => ['text' => "Here you are:\n```json\n" . json_encode([
        '1' => 'Otvoreno {{snippet:hours}} svaki dan',
        '2' => '<p>Bok <strong>ti</strong><script>alert(1)</script></p>',
        '3' => 'Obične <b>riječi</b>',
        '4' => 'Izgubljena oznaka',
    ], JSON_UNESCAPED_UNICODE) . "\n```", 'in' => 1, 'out' => 1];
    $result = AiTranslator::translate($texts, 'English (en)', 'Hrvatski (hr)', ['keep' => '', 'voice' => ''], $answer);

    assertEquals('Otvoreno {{snippet:hours}} svaki dan', $result['done']['a'] ?? null, 'a tag kept, the fence read past');
    assertTrue(isset($result['done']['b']) && !str_contains($result['done']['b'], 'script') && str_contains($result['done']['b'], '<strong>ti</strong>'), 'rich text through the sanitiser');
    assertEquals(['c', 'd'], $result['failed'], 'markup in plain text, and a lost tag, keep the original');
    assertTrue(!isset($result['done']['e']), 'nothing sent for blank text');

    $noJson = static fn (string $s, string $u): array => ['text' => 'Sorry, I cannot.', 'in' => 1, 'out' => 1];
    $threw = false;
    try {
        AiTranslator::translate(['x' => ['text' => 'Hi', 'html' => false]], 'en', 'hr', ['keep' => '', 'voice' => ''], $noJson);
    } catch (AiFailure) {
        $threw = true;
    }
    assertTrue($threw, 'an answer that is no JSON is a failure');
    assertContains('Never translate these words and names: Boxlet, Printworks.', AiTranslator::system('en', 'hr', ['keep' => "Boxlet\nPrintworks", 'voice' => '']), 'the owner\'s words to keep, told');
});

testBothDrivers('a page is translated part by part into its draft, and nothing is published', function (string $driver) {
    $db = aiSite($driver);
    $source = sourcePage($db);
    $db->query('UPDATE pages SET seo_json = ? WHERE id = ?', [PageSeo::json(['title' => 'About the press', 'description' => 'Who we are.']), $source]);
    $copy = Translations::create($db, blockRegistry(), $source, 'hr');
    if (!is_int($copy)) {
        fail('no copy: ' . $copy);
    }

    assertEquals(3, AiQueue::page($db, blockRegistry(), $copy), 'the page\'s own words and its two blocks');
    assertEquals(0, AiQueue::page($db, blockRegistry(), $copy), 'not queued twice');
    aiRunAll($db, aiEcho());

    $draft = PageDraft::current($db, blockRegistry(), $copy);
    if ($draft === null) {
        fail('no draft');
    }
    assertTrue($draft['drafted'], 'written into a draft');
    $doc = $draft['document'];
    assertEquals('HR About us', $doc['title'], 'the title');
    assertEquals('hr-about-us', $doc['slug'], 'a page never published takes an address in its own language');
    assertEquals(['title' => 'HR About the press', 'description' => 'HR Who we are.'], array_intersect_key(PageSeo::of(['seo_json' => $doc['seo_json']]), ['title' => 1, 'description' => 1]), 'what it tells search engines');
    assertEquals('HR Hello', $doc['blocks'][0]['content']['heading'] ?? null, 'the hero\'s heading');
    assertEquals('HR Contact', $doc['blocks'][0]['content']['cta']['label'] ?? null, 'a link\'s words');
    assertEquals('/contact', $doc['blocks'][0]['content']['cta']['url'] ?? null, 'its address as it was');
    assertEquals('<p>HR Some words.</p>', $doc['blocks'][1]['content']['body'] ?? null, 'rich text, markup kept');
    assertEquals('About us', $db->one('SELECT title FROM pages WHERE id = ?', [$copy])['title'] ?? null, 'the stored page untouched');
    assertEquals(['done' => 3, 'failed' => 0, 'pending' => 0], array_intersect_key(AiQueue::progress($db), ['done' => 1, 'failed' => 1, 'pending' => 1]), 'all done');
    assertEquals(['in' => 30, 'out' => 36], AiSettings::usage($db), 'the tokens counted');
});

testBothDrivers('a stale block is translated again from what the original says now, and is up to date', function (string $driver) {
    $db = aiSite($driver);
    $source = sourcePage($db);
    $copy = (int) Translations::create($db, blockRegistry(), $source, 'hr');
    $db->query("UPDATE page_blocks SET content_json = ? WHERE page_id = ? AND block_type = 'text'", [json_encode(['heading' => 'New words', 'body' => '<p>Changed.</p>']), $source]);
    $stale = array_keys(TranslationStatus::of($db, blockRegistry(), $copy)['stale']);
    assertEquals(1, count($stale), 'the text block is stale');

    $response = adminPost('/admin/pages/' . $copy . '/blocks/' . $stale[0] . '/ai', []);
    assertRedirectedTo('/admin/translate?back=' . $copy, $response);
    aiRunAll($db, aiEcho());

    $doc = PageDraft::current($db, blockRegistry(), $copy)['document'] ?? [];
    $text = array_values(array_filter($doc['blocks'] ?? [], static fn (array $b): bool => $b['type'] === 'text'))[0] ?? [];
    assertEquals('HR New words', $text['content']['heading'] ?? null, 'from the original\'s new words');
    assertEquals([], TranslationStatus::of($db, blockRegistry(), $copy)['stale'], 'no longer stale');
});

testBothDrivers('a run stops on a refused key and keeps the part; a part that fails alone is marked and the rest go on', function (string $driver) {
    $db = aiSite($driver);
    $copy = (int) Translations::create($db, blockRegistry(), sourcePage($db), 'hr');
    AiQueue::page($db, blockRegistry(), $copy);
    $refused = static function (Container $c): void {
        $c->set('ai_ask', static fn () => static function (): array {
            throw new AiFailure(t('ai.error.key'), 'invalid x-api-key');
        });
    };
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    try {
        $stop = dispatch('/admin/translate/step', null, 'POST', ['_csrf' => (new App\Core\Session())->csrfToken()], '203.0.113.10', $refused);
    } finally {
        unset($_SERVER['HTTP_ACCEPT']);
    }
    assertEquals(422, $stop->status, 'stopped');
    $said = json_decode($stop->body, true);
    assertEquals(t('ai.error.key'), $said['stopped'] ?? null, 'said why');
    assertEquals(3, $said['progress']['pending'] ?? null, 'nothing lost: every part still waiting');

    $calls = 0;
    aiRunAll($db, static function (string $s, string $u) use (&$calls): array {
        $calls++;

        return $calls === 2 ? ['text' => 'not json', 'in' => 0, 'out' => 0] : aiEcho()($s, $u);
    });
    $progress = AiQueue::progress($db);
    assertEquals([2, 1, 0], [$progress['done'], $progress['failed'], $progress['pending']], 'one failed, the others done');
    assertEquals([t('ai.error.not_json')], $progress['messages'], 'and why');
    AiQueue::retry($db);
    assertEquals(1, AiQueue::progress($db)['pending'], 'Try again puts it back');
});

testBothDrivers('Translate with AI is offered only with a key, and queues what it makes', function (string $driver) {
    $db = adminSite($driver);
    $source = sourcePage($db);
    assertTrue(!str_contains(dispatch('/admin/pages/' . $source)->body, e(t('ai.translate'))), 'no key: not offered');

    AiSettings::save($db, ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'model_other' => '', 'key' => AI_KEY, 'keep' => '', 'voice' => '', 'remove_key' => false], (string) TestSite::$env['APP_KEY']);
    assertContains(e(t('ai.translate')), dispatch('/admin/pages/' . $source)->body, 'the editor\'s language menu');
    assertContains(e(t('ai.translate_into', ['language' => 'Hrvatski'])), dispatch('/admin/pages')->body, 'a row\'s menu');
    assertContains(e(t('ai.bulk')), dispatch('/admin/pages')->body, 'Copy into language');

    $response = adminPost('/admin/pages/' . $source . '/translate', ['locale' => 'hr', 'ai' => '1']);
    $copy = Translations::of($db, $source)['hr'] ?? 0;
    assertRedirectedTo('/admin/translate?back=' . $copy, $response);
    assertEquals(3, AiQueue::progress($db)['pending'], 'queued');
    assertContains(e(t('ai.progress.title')), dispatch('/admin/translate?back=' . $copy)->body, 'the screen that runs it');

    $other = createPage($db, 'en', 'team', 'Team', true, [['type' => 'text', 'content' => ['body' => '<p>We.</p>']]]);
    $bulk = adminPost('/admin/pages/copy', ['ids' => [(string) $other], 'to' => 'hr', 'ai' => '1']);
    assertRedirectedTo('/admin/translate?back=' . (Translations::of($db, $other)['hr'] ?? 0), $bulk);
});
