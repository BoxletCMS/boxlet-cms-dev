<?php

namespace App\Modules\Pages;

use App\Core\Blocks;
use App\Core\Container;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;

/**
 * THE DRAFT AS JSON (PLAN.md D-163, D-173, README 2.2): what the builder autosaves, a whole page
 * as one document, and what it reads back.
 *
 * `POST /admin/pages/{id}/draft` takes `{"version": n, "document": {…}}` with the CSRF token in
 * an X-CSRF-Token header, and answers `{"version", "savedAt"}`; a version the draft has moved
 * past answers 409 with the draft's own, so the editor can say the page changed elsewhere and
 * offer to reload. No form, so no max_input_vars: a page is one field however long it is.
 *
 * A draft may be incomplete — an empty title, an address being typed — and is saved as it is:
 * Publish is where a page is checked (PagePublish::problems).
 */
final class PageDraftController
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, string $locale, array $params): Response
    {
        $id = (int) $params['id'];
        $page = Page::find($this->db(), $id);
        $current = $page === null ? null : PageDraft::current($this->db(), $this->registry(), $id);
        if ($page === null || $current === null) {
            return self::json(['error' => t('pages.not_found')], 404);
        }

        return self::json([
            'version' => $current['version'],
            'state' => PageDraft::state($this->db(), $page),
            'document' => $current['document'],
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function save(Request $request, string $locale, array $params): Response
    {
        $db = $this->db();
        $id = (int) $params['id'];
        $page = Page::find($db, $id);
        if ($page === null) {
            return self::json(['error' => t('pages.not_found')], 404);
        }
        $from = $request->body['version'] ?? null;
        $document = PageDocument::read($this->registry(), $request->body['document'] ?? null);
        if (!is_int($from) || $from < 0 || $document === null) {
            return self::json(['error' => t('pages.draft.unreadable')], 422);
        }
        // Whether the page is on the site is the page's, never the draft's to say.
        $document['status'] = (string) $page['status'];

        $version = PageDraft::save($db, $id, $document, $from, PagePublish::admin($this->container));
        if ($version === null) {
            $current = PageDraft::current($db, $this->registry(), $id);

            return self::json(['error' => t('pages.draft.conflict'), 'version' => $current['version'] ?? 0], 409);
        }

        return self::json(['version' => $version, 'savedAt' => gmdate('Y-m-d\TH:i:s\Z')]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data, int $status = 200): Response
    {
        return new Response(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
        );
    }

    private function registry(): Blocks
    {
        return $this->container->get('blocks');
    }

    private function db(): Db
    {
        return $this->container->get('db');
    }
}
