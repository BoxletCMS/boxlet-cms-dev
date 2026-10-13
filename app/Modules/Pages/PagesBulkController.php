<?php

namespace App\Modules\Pages;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\Activity;
use App\Support\Url;

/**
 * SEVERAL PAGES DELETED AT ONCE (PLAN.md D-218, the owner), or copied into another language
 * (D-247): the pages ticked in the list, each
 * deleted as the row's own Delete deletes it — its blocks, drafts and revisions with it, its
 * old addresses and its menu items (Page::delete) — and recorded one by one in the activity.
 * The way the owner clears the demo, with Media's after it: a picture is free to delete once
 * no page shows it.
 */
final class PagesBulkController
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(Request $request, string $locale, array $params): Response
    {
        $db = $this->container->get('db');
        $deleted = 0;
        foreach (self::ids($request) as $id) {
            $page = Page::find($db, $id);
            if ($page === null) {
                continue;
            }
            Page::delete($db, $id);
            Activity::record($db, 'page', 'deleted', $id, (string) $page['title']);
            $deleted++;
        }
        if ($deleted > 0) {
            Sitemap::refresh($this->container);
        }
        $session = $this->container->get('session');
        $session->set('flash', $deleted === 0
            ? t('pages.bulk.none')
            : tn('pages.bulk.deleted_many', $deleted));
        if ($deleted === 0) {
            $session->set('flash_kind', 'warning');
        }

        // Back to the list as it was filtered.
        $query = array_filter(['lang' => $request->input('lang'), 'q' => $request->input('q')]);

        return Response::redirect(Url::withQuery(Url::admin('pages'), $query));
    }

    /**
     * SEVERAL PAGES COPIED INTO ANOTHER LANGUAGE AT ONCE (PLAN.md D-247, the owner): each ticked
     * page's version in that language made as a draft copy, as the builder's Translate makes one
     * (TranslationController::translate). A page above its subpages first, so each subpage lands
     * under its parent's new version (Translations::parentIn). A page already in that language,
     * or one whose version there exists, is passed over and counted.
     *
     * @param array<string, string> $params
     */
    public function copy(Request $request, string $locale, array $params): Response
    {
        $db = $this->container->get('db');
        $to = trim($request->input('to'));
        $session = $this->container->get('session');
        $query = array_filter(['lang' => $request->input('lang'), 'q' => $request->input('q')]);
        $back = Response::redirect(Url::withQuery(Url::admin('pages'), $query));
        $label = $db->one('SELECT label FROM locales WHERE code = ? AND enabled = 1', [$to]);
        $ids = self::ids($request);
        if ($label === null || $ids === []) {
            $session->set('flash', $label === null ? t('translations.unknown_language') : t('pages.bulk.copy_none'));
            $session->set('flash_kind', 'warning');

            return $back;
        }

        // How deep each page stands: parents first, then the order they were ticked in.
        $parents = [];
        foreach ($db->all('SELECT id, parent_id FROM pages') as $row) {
            $parents[(int) $row['id']] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        }
        $depth = static function (int $id) use ($parents): int {
            $n = 0;
            for ($at = $parents[$id] ?? null; $at !== null && $n < 50; $at = $parents[$at] ?? null) {
                $n++;
            }

            return $n;
        };
        $order = array_flip($ids);
        usort($ids, static fn (int $a, int $b): int => [$depth($a), $order[$a]] <=> [$depth($b), $order[$b]]);

        $made = 0;
        $passed = 0;
        $new = [];
        foreach ($ids as $id) {
            $page = Page::find($db, $id);
            if ($page === null) {
                continue;
            }
            $result = $page['locale'] === $to ? null : TranslationController::translate($db, $this->container->get('blocks'), $id, $to);
            if ($result !== null && $result['new'] && is_int($result['id'])) {
                $made++;
                $new[] = $result['id'];
            } else {
                $passed++;
            }
        }
        $said = $made > 0 ? tn('pages.bulk.copied_many', $made, ['language' => (string) $label['label']]) : t('pages.bulk.copied_none', ['language' => (string) $label['label']]);
        if ($passed > 0) {
            $said .= ' ' . tn('pages.bulk.copy_passed_many', $passed, ['language' => (string) $label['label']]);
        }
        $session->set('flash', $said);
        if ($made === 0) {
            $session->set('flash_kind', 'warning');
        }

        // "and translate with AI" (D-248): what was made, queued.
        return \App\Modules\Ai\AiController::queue($this->container, $request, $new) ?? $back;
    }

    /**
     * The ticked ids, each once, each a positive integer; anything else is ignored.
     *
     * @return list<int>
     */
    public static function ids(Request $request): array
    {
        $raw = $request->body['ids'] ?? [];
        $ids = [];
        foreach (is_array($raw) ? $raw : [] as $value) {
            if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
                $ids[(int) $value] = (int) $value;
            }
        }

        return array_values($ids);
    }
}
