<?php

namespace App\Modules\Pages;

use App\Core\Blocks;
use App\Core\Container;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Admin\AdminView;
use App\Modules\Design\Composition;
use App\Support\Url;

/**
 * The visual page editor: a canvas showing the real page, with the block's own fields
 * beside it.
 *
 * The canvas is an iframe rendering the page exactly as a visitor gets it, so site CSS
 * and admin CSS cannot collide and what you see is what the page is. Blocks are not
 * wrapped in editor markup: sections.css styles a section by its position among its
 * siblings, so anything inserted between them would change the page being judged.
 * Selection is a class, and the insertion controls are an overlay.
 *
 * The form is the one from Slice 3, unchanged: every block's fields are real inputs in
 * one form, submitted by an explicit Save to the same endpoint and validated by the same
 * server-side code. The canvas decides which of those groups is on screen.
 */
final class PageBuilderController
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, string $locale, array $params): Response
    {
        $page = Page::find($this->db(), (int) $params['id']);
        if ($page === null) {
            return PagesController::missing();
        }

        return $this->shell(
            $page,
            (string) $page['title'],
            (string) $page['slug'],
            Page::editable($this->db(), $this->registry(), (int) $page['id']),
        );
    }

    /**
     * The page itself, for the canvas iframe: the same blocks, the same stylesheets and
     * the same markup the front end renders, plus the editor's own overlay.
     *
     * @param array<string, string> $params
     */
    public function canvas(Request $request, string $locale, array $params): Response
    {
        $page = Page::find($this->db(), (int) $params['id']);
        if ($page === null) {
            return PagesController::missing();
        }

        $registry = $this->registry();
        $html = '';
        foreach (Page::blocks($this->db(), (int) $page['id']) as $block) {
            if ($registry->has($block['type'])) {
                $html .= $registry->render($block['type'], $block['content'], $block['style'], $block['layout']);
            }
        }

        $body = (new View(__DIR__ . '/views'))->render('admin/canvas', $locale, [
            'title' => (string) $page['title'],
            'blocksHtml' => $html,
        ], null);

        $response = Response::admin($body);
        // The one admin document that may be framed, and only by the admin itself.
        $response->headers['Content-Security-Policy'] = "default-src 'self'; img-src 'self' data:; "
            . "form-action 'none'; frame-ancestors 'self'; base-uri 'none'";
        $response->headers['X-Frame-Options'] = 'SAMEORIGIN';

        return $response;
    }

    /**
     * Re-renders the builder after a save that did not validate, so the user stays in the
     * editor they were using. PageEditorController calls this; the parsing, validation
     * and storage it runs first are the same for both editors.
     *
     * @param array<string, mixed> $page
     * @param list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string>, layout: string}> $blocks
     * @param array<string, string> $errors
     */
    public function rejected(array $page, string $title, string $slug, array $blocks, array $errors, ?string $notice): Response
    {
        return $this->shell($page, $title, $slug, $blocks, $errors, $notice, 422);
    }

    /**
     * @param array<string, mixed> $page
     * @param list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string>, layout: string}> $blocks
     * @param array<string, string> $errors
     */
    private function shell(array $page, string $title, string $slug, array $blocks, array $errors = [], ?string $notice = null, int $status = 200): Response
    {
        $id = (int) $page['id'];

        return AdminView::render($this->container, __DIR__ . '/views', 'admin/builder', [
            'title' => t('pages.edit'),
            'nav' => 'pages',
            'styles' => ['builder.css'],
            'wide' => true,
            'bare' => true,
            'page' => $page,
            'titleValue' => $title,
            'slugValue' => $slug,
            'blocks' => $blocks,
            'errors' => $errors,
            'notice' => $notice,
            'character' => Composition::active($this->db()),
            'registry' => $this->registry(),
            'canvasUrl' => Url::admin('pages', $id, 'canvas'),
        ], $status);
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
