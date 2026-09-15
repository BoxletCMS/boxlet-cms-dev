<?php

namespace App\Modules\Pages;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Support\Url;

/**
 * Front end: renders a published page's blocks in order, or the 404 page.
 */
final class PageController
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, string> $params slug, absent for the home page
     */
    public function show(Request $request, string $locale, array $params): Response
    {
        $db = $this->container->get('db');
        $slug = $params['slug'] ?? '';
        $page = Page::published($db, $locale, $slug);
        if ($page === null) {
            return $this->notFound($request, $locale, $params);
        }

        $registry = $this->container->get('blocks');
        $html = '';
        foreach (Page::blocks($db, (int) $page['id']) as $block) {
            // A block whose type was removed from app/Blocks cannot render; skip it.
            if ($registry->has($block['type'])) {
                $html .= $registry->render($block['type'], $block['content'], $block['style'], $block['layout']);
            }
        }

        return $this->render('page', $locale, [
            'title' => (string) $page['title'],
            'blocksHtml' => $html,
            // The one address this page is indexed under, whatever variant reached it.
            'canonical' => Url::canonical($locale, $slug),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function notFound(Request $request, string $locale, array $params): Response
    {
        $copy = [
            'en' => ['title' => 'Page not found', 'intro' => 'There is no page at this address.'],
            'hr' => ['title' => 'Stranica nije pronađena', 'intro' => 'Na ovoj adresi nema stranice.'],
        ];

        return $this->render('404', $locale, $copy[$locale] ?? $copy['en'], 404);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(string $template, string $locale, array $data, int $status = 200): Response
    {
        $data += ['canonical' => null, 'locales' => $this->container->get('locales')];

        return Response::html((new View(__DIR__ . '/views'))->render($template, $locale, $data), $status);
    }
}
