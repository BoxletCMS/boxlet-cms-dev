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
use App\Modules\Design\Design;
use App\Modules\Design\SectionStyle;
use App\Modules\Media\MediaReference;
use App\Support\Url;

// PageTree supplies the parents a page may have; it excludes the page and its own
// descendants, which is what keeps a cycle out of the hierarchy.

// BlockForm cleans a block's submitted values; the canvas re-draws from the cleaned
// ones so it shows what a save would store.

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
        foreach ($this->canvasBlocks((int) $page['id']) as $block) {
            $content = $block['content'];
            // A block whose type this installation no longer has keeps its stored content
            // and simply does not draw.
            if ($content === null || !$registry->has($block['type'])) {
                continue;
            }
            $html .= $registry->render($block['type'], $content, $block['style'], $block['layout']);
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
     * One new block, as two fragments: the section for the canvas and the field group for
     * the form. Nothing is written — the block exists only in the page being edited until
     * Save, exactly like a block added in the fallback editor.
     *
     * The response is HTML, not JSON. The server owns what a block is; sending a schema
     * for the browser to render would put a second copy of the block definition in
     * JavaScript, which is the thing this design exists to avoid.
     *
     * @param array<string, string> $params
     */
    public function insert(Request $request, string $locale, array $params): Response
    {
        $page = Page::find($this->db(), (int) $params['id']);
        if ($page === null) {
            return PagesController::missing();
        }

        $registry = $this->registry();
        $type = $request->input('type');
        if (!$registry->has($type)) {
            return new Response(t('pages.insert_unknown'), 422, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $character = Composition::active($this->db());
        $block = [
            'id' => null,
            'type' => $type,
            'content' => $registry->normalize($type, []),
            'style' => Composition::style($character, $type),
            'layout' => Composition::layout($registry, $character, $type),
        ];

        // With values posted, this is a block being re-drawn as it is edited rather than
        // a new one. The same parser the save runs cleans them, so the canvas shows what
        // would actually be stored — including rich text reduced to the whitelist.
        $submitted = $request->body['block'] ?? null;
        if (is_array($submitted)) {
            $parsed = BlockForm::parse($registry, [['type' => $type] + $submitted], []);
            $first = $parsed['blocks'][0] ?? null;
            // A parsed block keeps a null content when its type is not installed, which
            // this method has already ruled out; building the block explicitly says so
            // rather than carrying the null through.
            if ($first !== null && is_array($first['content'])) {
                $block = [
                    'id' => null,
                    'type' => $first['type'],
                    'content' => $first['content'],
                    'style' => $first['style'],
                    'layout' => $first['layout'],
                ];
            }
        }

        $body = (new View(__DIR__ . '/views'))->render('admin/insert', $locale, [
            // The browser renumbers every group after inserting, so this index only has
            // to be unique in the returned markup.
            'index' => max(0, (int) $request->input('index')),
            'block' => $block,
            'errors' => [],
            'character' => $character,
            'registry' => $registry,
            'pictures' => MediaReference::choices($this->db()),
            'canvasHtml' => $registry->render($type, $block['content'], $block['style'], $block['layout']),
        ], null);

        return Response::admin($body);
    }

    /**
     * Re-renders the builder after a save that did not validate, so the user stays in the
     * editor they were using. PageEditorController calls this; the parsing, validation
     * and storage it runs first are the same for both editors.
     *
     * @param array<string, mixed> $page
     * @param list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string}> $blocks
     * @param array<string, string> $errors
     */
    public function rejected(array $page, string $title, string $slug, array $blocks, array $errors, ?string $notice): Response
    {
        // The canvas reloads when this renders, and it reads the database — which is
        // exactly what was NOT written. Without this, a rejected save appears to empty
        // the page while the fields are still full. Read once, by the next canvas.
        $this->container->get('session')->set('pending_canvas', [
            'page' => (int) $page['id'],
            'blocks' => $blocks,
        ]);

        return $this->shell($page, $title, $slug, $blocks, $errors, $notice, 422);
    }

    /**
     * What the canvas should draw: normally the stored page, but after a save that did
     * not validate, the blocks as they were submitted.
     *
     * @return list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string}>
     */
    private function canvasBlocks(int $pageId): array
    {
        $session = $this->container->get('session');
        $pending = $session->get('pending_canvas');
        $session->remove('pending_canvas');

        if (!is_array($pending) || ($pending['page'] ?? null) !== $pageId || !is_array($pending['blocks'] ?? null)) {
            return Page::editable($this->db(), $this->registry(), $pageId);
        }

        // Session data is rebuilt rather than trusted: it survives across requests, and
        // what it holds has to satisfy the same shape a stored block does.
        $blocks = [];
        foreach ($pending['blocks'] as $block) {
            if (!is_array($block) || !is_string($block['type'] ?? null)) {
                continue;
            }
            $content = $block['content'] ?? null;
            $blocks[] = [
                'id' => null,
                'type' => $block['type'],
                'content' => is_array($content) ? $content : null,
                'style' => SectionStyle::normalize($block['style'] ?? null),
                'layout' => is_string($block['layout'] ?? null) ? $block['layout'] : '',
            ];
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $page
     * @param list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string}> $blocks
     * @param array<string, string> $errors
     */
    private function shell(array $page, string $title, string $slug, array $blocks, array $errors = [], ?string $notice = null, int $status = 200): Response
    {
        $id = (int) $page['id'];

        return AdminView::render($this->container, __DIR__ . '/views', 'admin/builder', [
            'title' => t('pages.edit'),
            'nav' => 'pages',
            'styles' => ['admin-richtext.css', 'builder.css', 'builder-inspector.css'],
            'scripts' => ['vendor/tiptap.bundle.min.js', 'richtext.js'],
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
            'insertUrl' => Url::admin('pages', $id, 'block'),
            'library' => $this->library(),
            // What a media field offers. The editor asks for a picture by name, never by id.
            'pictures' => MediaReference::choices($this->db()),
            // Page settings live in the panel beside the canvas. Offering a parent is the
            // only place a cycle could be created, so the list already excludes this page
            // and everything under it (PageTree).
            'parents' => PageTree::parentOptions($this->db(), (string) $page['locale'], $id),
        ], $status);
    }

    /**
     * Every block that can be added, each with a picture of itself rendered from the
     * block and this site's design (BlockPreview). Missing files are generated here, the
     * same guard the compiled stylesheet uses.
     *
     * @return list<array{type: string, label: string, preview: string}>
     */
    private function library(): array
    {
        $cache = (string) $this->container->get('config')->get('app.cache_path');
        $stylesheet = Design::stylesheet($this->db(), $cache);
        $registry = $this->registry();

        $library = [];
        foreach ($registry->types() as $type) {
            $library[] = [
                'type' => $type,
                'label' => t('block.' . $type),
                'preview' => Url::asset('cache/previews/' . BlockPreview::file($registry, $type, $stylesheet, $cache)),
            ];
        }

        return $library;
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
