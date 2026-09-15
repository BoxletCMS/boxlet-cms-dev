<?php

namespace App\Modules\Pages;

use App\Core\Container;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\AdminView;
use App\Modules\Design\Composition;
use App\Support\Url;

/**
 * Admin: the page list, creating, publishing and deleting pages. Editing a page's
 * content is PageEditorController.
 */
final class PagesController
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, string $locale, array $params): Response
    {
        return AdminView::render($this->container, __DIR__ . '/views', 'admin/index', [
            'title' => t('pages.title'),
            'nav' => 'pages',
            'styles' => ['admin-pages.css'],
            'pages' => Page::all($this->db()),
            'localeLabels' => array_column($this->container->get('locales'), 'label', 'code'),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(Request $request, string $locale, array $params): Response
    {
        return $this->form([], []);
    }

    /**
     * @param array<string, string> $params
     */
    public function store(Request $request, string $locale, array $params): Response
    {
        $db = $this->db();
        $title = trim($request->input('title'));
        $pageLocale = $request->input('locale');
        $home = $request->input('home') === '1';
        $slug = $home ? '' : trim($request->input('slug'));

        $errors = [];
        if ($title === '') {
            $errors['title'] = t('pages.title_required');
        }
        if (!in_array($pageLocale, array_column($this->container->get('locales'), 'code'), true)) {
            $errors['locale'] = t('pages.locale_invalid');
        }
        $template = null;
        foreach (Page::templates($db) as $candidate) {
            if ((string) $candidate['id'] === $request->input('template')) {
                $template = $candidate;
            }
        }
        if ($template === null && $request->input('template') !== '') {
            $errors['template'] = t('pages.template_invalid');
        }
        if ($errors === []) {
            if (!$home && $slug === '') {
                $slug = Slug::unique($db, $pageLocale, $title, null);
            }
            $problem = Slug::problem($db, $pageLocale, $slug, null);
            if ($problem !== null) {
                $errors[$home ? 'home' : 'slug'] = $problem;
            }
        }
        if ($errors !== []) {
            return $this->form($request->body, $errors, 422);
        }

        $registry = $this->container->get('blocks');
        $types = [];
        foreach ($template['blocks'] ?? [] as $type) {
            if ($registry->has($type)) {
                $types[] = $type;
            }
        }
        $id = Page::create($db, $registry, $pageLocale, $title, $slug, $template['id'] ?? null, $types, Composition::active($db));
        $this->container->get('session')->set('flash', t('pages.created'));

        return Response::redirect(Url::admin('pages', $id));
    }

    /**
     * @param array<string, string> $params
     */
    public function status(Request $request, string $locale, array $params): Response
    {
        $id = (int) $params['id'];
        if (Page::find($this->db(), $id) === null) {
            return self::missing();
        }
        $published = $request->input('status') === 'published';
        Page::setStatus($this->db(), $id, $published);
        $this->container->get('session')->set('flash', t($published ? 'pages.published' : 'pages.unpublished'));

        return Response::redirect($request->input('return') === 'edit' ? Url::admin('pages', $id) : Url::admin('pages'));
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(Request $request, string $locale, array $params): Response
    {
        $id = (int) $params['id'];
        if (Page::find($this->db(), $id) === null) {
            return self::missing();
        }
        Page::delete($this->db(), $id);
        $this->container->get('session')->set('flash', t('pages.deleted'));

        return Response::redirect(Url::admin('pages'));
    }

    public static function missing(): Response
    {
        return Response::admin(e(t('pages.not_found')), 404);
    }

    /**
     * @param array<mixed>          $old
     * @param array<string, string> $errors
     */
    private function form(array $old, array $errors, int $status = 200): Response
    {
        return AdminView::render($this->container, __DIR__ . '/views', 'admin/create', [
            'title' => t('pages.new'),
            'nav' => 'pages',
            'styles' => ['admin-pages.css'],
            'old' => $old,
            'errors' => $errors,
            'locales' => $this->container->get('locales'),
            'templates' => Page::templates($this->db()),
        ], $status);
    }

    private function db(): Db
    {
        return $this->container->get('db');
    }
}
