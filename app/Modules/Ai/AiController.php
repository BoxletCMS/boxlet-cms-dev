<?php

namespace App\Modules\Ai;

use App\Core\Container;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\Activity;
use App\Modules\Admin\AdminView;
use App\Modules\Pages\BlockForm;
use App\Support\Url;

/**
 * AI TRANSLATION IN THE ADMIN (PLAN.md D-248, the owner): its panel in Settings and the screen
 * where a translation runs. The screen's script asks for one part at a time (step) and draws how
 * far it is; without the script, "Translate the next part" does the same by hand.
 */
final class AiController
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * The panel saved, or its errors carried back to it once.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, string $locale, array $params): Response
    {
        $input = [
            'provider' => $request->input('ai_provider'),
            'model' => $request->input('ai_model'),
            'model_other' => $request->input('ai_model_other'),
            'key' => $request->input('ai_key'),
            'keep' => $request->input('ai_keep'),
            'voice' => $request->input('ai_voice'),
            'remove_key' => $request->input('ai_remove_key') === '1',
        ];
        $errors = AiSettings::save($this->db(), $input, $this->appKey());
        $session = $this->container->get('session');
        if ($errors !== []) {
            unset($input['key']);
            $session->set('ai_form', ['errors' => $errors, 'old' => $input]);
            $session->set('flash', t('ai.not_saved'));
            $session->set('flash_kind', 'error');
        } else {
            Activity::record($this->db(), 'settings', 'ai_saved', null, '');
            $session->set('flash', t('ai.saved'));
        }

        return Response::redirect(Url::admin('settings') . '#ai');
    }

    /**
     * One sentence translated into the site's main language, to prove the key and model work.
     *
     * @param array<string, string> $params
     */
    public function test(Request $request, string $locale, array $params): Response
    {
        $session = $this->container->get('session');
        $db = $this->db();
        $main = (string) ($db->one('SELECT code, label FROM locales WHERE is_primary = 1')['label'] ?? 'English');
        $into = $main === 'English' ? 'Hrvatski (hr)' : $main;
        try {
            $words = AiSettings::forRequest($db, $this->appKey()) ?? throw new AiFailure(t('ai.error.not_ready'));
            $result = AiTranslator::translate(
                ['test' => ['text' => t('ai.test_sentence'), 'html' => false]],
                'English (en)',
                $into,
                $words,
                $this->container->get('ai_ask'),
            );
            AiSettings::addUsage($db, $result['in'], $result['out']);
            $session->set('flash', t('ai.test_done', ['text' => $result['done']['test'] ?? '']));
        } catch (AiFailure $e) {
            $session->set('flash', t('ai.test_failed', ['reason' => $e->getMessage()]));
            $session->set('flash_kind', 'error');
        }

        return Response::redirect(Url::admin('settings') . '#ai');
    }

    /**
     * The screen where a translation runs, and says how far it is.
     *
     * @param array<string, string> $params
     */
    public function progress(Request $request, string $locale, array $params): Response
    {
        $back = is_string($request->query['back'] ?? null) && ctype_digit($request->query['back']) ? (int) $request->query['back'] : null;

        return AdminView::render($this->container, __DIR__ . '/views', 'progress', [
            'title' => t('ai.progress.title'),
            'nav' => 'pages',
            'styles' => ['admin-ai.css'],
            'scripts' => ['ai-progress.js'],
            'progress' => AiQueue::progress($this->db()),
            'ready' => AiSettings::ready($this->db(), $this->appKey()),
            'back' => $back,
        ]);
    }

    /**
     * ONE PART TRANSLATED: the oldest waiting. A failure is kept on its row with its sentence and
     * the rest carry on; a provider that cannot be reached at all stops the run, so a wrong key
     * is said once rather than for every block.
     *
     * @param array<string, string> $params
     */
    public function step(Request $request, string $locale, array $params): Response
    {
        $db = $this->db();
        $next = AiQueue::next($db);
        $stopped = '';
        if ($next !== null) {
            try {
                $words = AiSettings::forRequest($db, $this->appKey()) ?? throw new AiFailure(t('ai.error.not_ready'));
                @set_time_limit(120);
                $kept = AiWork::run($db, $this->container->get('blocks'), $next['page_id'], $next['part'], $words, $this->container->get('ai_ask'));
                AiQueue::finish($db, $next['id'], 'done', $kept);
            } catch (AiFailure $e) {
                if ($e->provider !== '' || in_array($e->getMessage(), [t('ai.error.not_ready'), t('ai.error.key'), t('ai.error.credit'), t('ai.error.no_curl')], true)) {
                    // Nothing more will get through: the run stops here, this part still waiting.
                    $stopped = $e->getMessage();
                } else {
                    AiQueue::finish($db, $next['id'], 'failed', $e->getMessage());
                }
            }
        }
        $progress = AiQueue::progress($db);
        if ($progress['pending'] === 0 && $next !== null && $stopped === '') {
            $pages = array_column($progress['pages'], 'title');
            Activity::record($db, 'page', 'ai_translated', null, implode(', ', array_slice($pages, 0, 3)));
        }
        if (str_contains((string) $request->header('accept'), 'application/json')) {
            return Response::json(['progress' => $progress, 'stopped' => $stopped], $stopped === '' ? 200 : 422);
        }
        if ($stopped !== '') {
            $session = $this->container->get('session');
            $session->set('flash', $stopped);
            $session->set('flash_kind', 'error');
        }

        return Response::redirect(Url::admin('translate'));
    }

    /**
     * @param array<string, string> $params
     */
    public function retry(Request $request, string $locale, array $params): Response
    {
        AiQueue::retry($this->db());

        return Response::redirect(Url::admin('translate'));
    }

    /**
     * @param array<string, string> $params
     */
    public function stop(Request $request, string $locale, array $params): Response
    {
        AiQueue::clear($this->db());
        $this->container->get('session')->set('flash', t('ai.progress.stopped'));

        return Response::redirect(Url::admin('pages'));
    }

    /**
     * One block of a translation translated again from what its original says now: the button
     * beside a stale block (D-043, step 3).
     *
     * @param array<string, string> $params
     */
    public function block(Request $request, string $locale, array $params): Response
    {
        $pageId = (int) $params['id'];
        AiQueue::block($this->db(), $pageId, BlockForm::key((int) $params['block'], 0));

        return Response::redirect(Url::withQuery(Url::admin('translate'), ['back' => (string) $pageId]));
    }

    /**
     * After Translate, Copy into language or Translate again: the pages queued, and the owner
     * taken to the screen that runs it. Null when AI translation was not asked for or is not set.
     *
     * @param list<int> $pageIds
     */
    public static function queue(Container $container, Request $request, array $pageIds): ?Response
    {
        $db = $container->get('db');
        if ($request->input('ai') !== '1' || $pageIds === [] || !AiSettings::ready($db, (string) $container->get('config')->get('app.key'))) {
            return null;
        }
        foreach ($pageIds as $id) {
            AiQueue::page($db, $container->get('blocks'), $id);
        }

        return Response::redirect(Url::withQuery(Url::admin('translate'), count($pageIds) === 1 ? ['back' => (string) $pageIds[0]] : []));
    }

    private function appKey(): string
    {
        return (string) $this->container->get('config')->get('app.key');
    }

    private function db(): Db
    {
        return $this->container->get('db');
    }
}
