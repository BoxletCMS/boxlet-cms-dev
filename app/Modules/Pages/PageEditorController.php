<?php

namespace App\Modules\Pages;

use App\Core\Blocks;
use App\Core\Container;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\AdminView;
use App\Modules\Design\Composition;
use App\Modules\Design\SectionStyle;
use App\Support\Url;

/**
 * The page editor: one form holding every block's fields, submitted as one POST.
 *
 * JavaScript only adds, removes and reorders field groups. Without it the same buttons
 * submit, and the server re-renders the form with the change applied but nothing
 * saved. Only Save writes to the database.
 */
final class PageEditorController
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

        return $this->form($page, (string) $page['title'], (string) $page['slug'], $this->storedBlocks((int) $page['id']));
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, string $locale, array $params): Response
    {
        $db = $this->db();
        $page = Page::find($db, (int) $params['id']);
        if ($page === null) {
            return PagesController::missing();
        }
        $id = (int) $page['id'];

        // PHP drops input past max_input_vars without any error. Saving what arrived would
        // silently delete content, so a form that did not arrive whole is refused.
        if (self::truncated($request)) {
            $message = t('pages.editor.truncated', ['limit' => (int) ini_get('max_input_vars')]);

            return $this->form($page, (string) $page['title'], (string) $page['slug'], $this->storedBlocks($id), [], $message, 422);
        }

        $registry = $this->registry();
        $storedTypes = [];
        foreach (Page::blocks($db, $id) as $stored) {
            $storedTypes[$stored['id']] = $stored['type'];
        }
        $parsed = BlockForm::parse($registry, $request->body['blocks'] ?? [], $storedTypes);
        $blocks = $parsed['blocks'];
        $title = trim($request->input('title'));
        $slug = trim($request->input('slug'));
        $action = $request->input('action');

        if ($action === 'add' && $registry->has($request->input('add_type'))) {
            $type = $request->input('add_type');
            $character = Composition::active($db);
            $blocks[] = [
                'id' => null,
                'type' => $type,
                'content' => $registry->normalize($type, []),
                'style' => Composition::style($character, $type),
                'layout' => Composition::layout($registry, $character, $type),
            ];

            return $this->form($page, $title, $slug, $blocks);
        }
        if (preg_match('~^(up|down)-(\d+)$~', $action, $move)) {
            return $this->form($page, $title, $slug, BlockForm::move($blocks, (int) $move[2], $move[1]));
        }

        $errors = $parsed['errors'];
        if ($title === '') {
            $errors['title'] = t('pages.title_required');
        }
        $slugProblem = Slug::problem($db, (string) $page['locale'], $slug, $id);
        if ($slugProblem !== null) {
            $errors['slug'] = $slugProblem;
        }
        if ($errors !== []) {
            return $this->form($page, $title, $slug, $blocks, $errors, t('pages.editor.errors'), 422);
        }

        Page::update($db, $id, $title, $slug, $blocks);
        $this->container->get('session')->set('flash', t('pages.saved'));

        return Response::redirect(Url::admin('pages', $id));
    }

    /**
     * True when the form did not arrive whole: its last field, _end, is missing, or the
     * number of fields reached max_input_vars.
     */
    private static function truncated(Request $request): bool
    {
        if (($request->body['_end'] ?? null) !== '1') {
            return true;
        }
        $limit = (int) ini_get('max_input_vars');

        return $limit > 0 && self::countFields($request->body) >= $limit;
    }

    /**
     * @param array<mixed> $values
     */
    private static function countFields(array $values): int
    {
        $count = 0;
        foreach ($values as $value) {
            $count += is_array($value) ? self::countFields($value) : 1;
        }

        return $count;
    }

    /**
     * @return list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string>, layout: string}>
     */
    private function storedBlocks(int $pageId): array
    {
        $registry = $this->registry();
        $blocks = [];
        foreach (Page::blocks($this->db(), $pageId) as $block) {
            $known = $registry->has($block['type']);
            $blocks[] = [
                'id' => $block['id'],
                'type' => $block['type'],
                'content' => $known ? $registry->normalize($block['type'], $block['content']) : null,
                'style' => SectionStyle::normalize($block['style']),
                'layout' => $known ? $registry->layout($block['type'], $block['layout']) : '',
            ];
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $page
     * @param list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string>, layout: string}> $blocks
     * @param array<string, string> $errors
     */
    private function form(array $page, string $title, string $slug, array $blocks, array $errors = [], ?string $notice = null, int $status = 200): Response
    {
        return AdminView::render($this->container, __DIR__ . '/views', 'admin/edit', [
            'title' => t('pages.edit'),
            'nav' => 'pages',
            'styles' => ['admin-pages.css'],
            'character' => Composition::active($this->db()),
            'page' => $page,
            'titleValue' => $title,
            'slugValue' => $slug,
            'blocks' => $blocks,
            'errors' => $errors,
            'notice' => $notice,
            'registry' => $this->registry(),
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
