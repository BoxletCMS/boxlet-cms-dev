<?php

namespace App\Modules\Pages;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Media\MediaPicture;
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
        $blocks = Page::blocks($db, (int) $page['id']);
        // Every picture this page refers to, in one query rather than one per block, and
        // before anything renders: a template is handed what it needs and never queries.
        $media = MediaPicture::forBlocks($db, $registry, $locale, $blocks);

        $html = '';
        $first = true;
        foreach ($blocks as $block) {
            // A block whose type was removed from app/Blocks cannot render; skip it.
            if (!$registry->has($block['type'])) {
                continue;
            }
            // Only the first section that actually draws is eager. Everything below the
            // fold is lazy, which is the whole point of loading="lazy" — and the first
            // picture is usually the one a visitor is waiting to see.
            $html .= $registry->render($block['type'], $block['content'], $block['style'], $block['layout'], $media, $first);
            $first = false;
        }

        // D-004. THE ONLY PLACE THE TITLE FALLS BACK. An empty <title> is worse than one
        // repeating the page's own, so the page title stands in; an empty description is
        // better than one repeating the title, so it stays empty and the tag is dropped.
        $seo = Page::seo($page);

        return $this->render('page', $locale, [
            'title' => $seo['title'] !== '' ? $seo['title'] : (string) $page['title'],
            'description' => $seo['description'],
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
        // The error pages have no description of their own, and neither has anything
        // else that renders through this layout: defaulting it here is what keeps the
        // template free of a guard around a variable that is simply always present.
        $data += ['canonical' => null, 'description' => '', 'locales' => $this->container->get('locales')];

        return Response::html((new View(__DIR__ . '/views'))->render($template, $locale, $data), $status);
    }
}
