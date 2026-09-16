<?php

namespace App\Modules\Pages;

use App\Core\Blocks;
use App\Core\Container;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\AdminView;
use App\Modules\Design\Composition;
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

            return $this->reject($request, $page, (string) $page['title'], (string) $page['slug'], $this->storedBlocks($id), [], $message);
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

        // The plain editor sends no settings fields, so each falls back to what the page
        // already has. Only the visual editor's page panel submits them.
        $settings = self::settings($request, $db, $page, $id);
        $errors = $parsed['errors'];
        if ($settings['parent_id'] === false) {
            $errors['parent'] = t('pages.parent_invalid');
            $settings['parent_id'] = $page['parent_id'] === null ? null : (int) $page['parent_id'];
        }
        if ($title === '') {
            $errors['title'] = t('pages.title_required');
        }
        $slugProblem = Slug::problem($db, (string) $page['locale'], $slug, $id);
        if ($slugProblem !== null) {
            $errors['slug'] = $slugProblem;
        }
        if ($errors !== []) {
            // What was submitted, so a rejected save shows the settings the user chose
            // rather than the ones still stored.
            return $this->reject($request, $settings + $page, $title, $slug, $blocks, $errors, t('pages.editor.errors'));
        }

        Page::update($db, $id, ['title' => $title, 'slug' => $slug] + $settings, $blocks);
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
     * The page's settings as submitted, falling back to what is stored for any the form
     * did not send.
     *
     * parent_id comes back as false when a parent was named that this page may not have.
     * The list of allowed parents already excludes the page and its descendants, so this
     * is what stops a crafted request creating a cycle the interface would not offer.
     *
     * @param array<string, mixed> $page
     * @return array{parent_id: int|null|false, status: string}
     */
    private static function settings(Request $request, Db $db, array $page, int $id): array
    {
        $stored = $page['parent_id'] === null ? null : (int) $page['parent_id'];
        $status = $request->input('status');
        $settings = [
            'parent_id' => $stored,
            'status' => in_array($status, ['draft', 'published'], true) ? $status : (string) $page['status'],
        ];

        if (!array_key_exists('parent_id', $request->body)) {
            return $settings;
        }
        $submitted = $request->input('parent_id');
        if ($submitted === '') {
            $settings['parent_id'] = null;

            return $settings;
        }

        $allowed = array_column(PageTree::parentOptions($db, (string) $page['locale'], $id), 'id');
        $settings['parent_id'] = in_array((int) $submitted, $allowed, true) ? (int) $submitted : false;

        return $settings;
    }

    /**
     * A save that did not validate re-renders the editor it was sent from, so nobody is
     * moved to a different screen at the moment they have to fix something. Everything
     * before this point — parsing, validation, storage — is the same for both.
     *
     * @param array<string, mixed> $page
     * @param list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string>, layout: string}> $blocks
     * @param array<string, string> $errors
     */
    private function reject(Request $request, array $page, string $title, string $slug, array $blocks, array $errors, ?string $notice): Response
    {
        if ($request->input('editor') === 'builder') {
            return (new PageBuilderController($this->container))->rejected($page, $title, $slug, $blocks, $errors, $notice);
        }

        return $this->form($page, $title, $slug, $blocks, $errors, $notice, 422);
    }

    /**
     * @return list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string>, layout: string}>
     */
    private function storedBlocks(int $pageId): array
    {
        return Page::editable($this->db(), $this->registry(), $pageId);
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
            'styles' => ['admin-richtext.css', 'admin-pages.css'],
            'scripts' => ['vendor/tiptap.bundle.min.js', 'richtext.js'],
            'character' => Composition::active($this->db()),
            'page' => $page,
            'titleValue' => $title,
            'slugValue' => $slug,
            // update() is the save route for both editors and reads parent_id from the
            // request, so an editor that does not render the control submits nothing and
            // the page is un-parented on every save.
            'parents' => PageTree::parentOptions($this->db(), (string) $page['locale'], isset($page['id']) ? (int) $page['id'] : null),
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
