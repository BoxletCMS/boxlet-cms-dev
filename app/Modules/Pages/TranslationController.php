<?php

namespace App\Modules\Pages;

use App\Core\Container;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Support\Url;

/**
 * Translating a page (PLAN.md D-043, step 2): the builder's language menu posts here to
 * make the page's version in another language, and is taken to it. A version that already
 * exists is simply opened, so a second press is never a second copy.
 */
final class TranslationController
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function create(Request $request, string $locale, array $params): Response
    {
        $db = $this->db();
        $pageId = (int) $params['id'];
        $target = trim($request->input('locale'));
        $before = Translations::of($db, $pageId);
        $result = Translations::create($db, $this->container->get('blocks'), $pageId, $target);
        $session = $this->container->get('session');

        if (is_string($result)) {
            $session->set('flash', $result);
            $session->set('flash_kind', 'error');

            return Response::redirect(Url::admin('pages', $pageId));
        }
        if (!isset($before[$target])) {
            $label = (string) ($db->one('SELECT label FROM locales WHERE code = ?', [$target])['label'] ?? $target);
            $session->set('flash', t('translations.created', ['language' => $label]));
        }

        return Response::redirect(Url::admin('pages', $result));
    }

    private function db(): Db
    {
        return $this->container->get('db');
    }
}
