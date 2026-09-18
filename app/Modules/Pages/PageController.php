<?php

namespace App\Modules\Pages;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Media\MediaPicture;
use App\Modules\Menus\MenuTree;
use App\Modules\Settings\SiteChrome;
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
            // render() defaults this to null so an error page carries no link preview;
            // a real page is where the site's default sharing picture belongs (D-028).
            'shareImage' => SiteChrome::shareImage($db),
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
        //
        // The icon is computed here rather than in show(), so the 404 page carries it too —
        // a browser asks for it whatever the status. One indexed lookup per render, and
        // null when no favicon is chosen. A sharing picture is show()'s: a link preview of
        // an error page is not worth a row.
        $db = $this->container->get('db');
        $locales = $this->container->get('locales');

        $data += [
            'canonical' => null,
            'description' => '',
            'icon' => SiteChrome::icon($db),
            'shareImage' => null,
            'locales' => $locales,
        ] + $this->chrome($locale, $locales);

        return Response::html((new View(__DIR__ . '/views'))->render($template, $locale, $data), $status);
    }

    /**
     * The site's header and footer, drawn by the block machinery so they inherit the design
     * tokens and the section style layers (PLAN.md D-028, D-030).
     *
     * Computed here rather than in show(), for the reason the icon is: the 404 page carries
     * chrome too. A page that says "not found" without the site's own header around it
     * reads as a broken site rather than a wrong address.
     *
     * THE MENU IS RESOLVED ONCE, here, and handed to both templates — the same rule pictures
     * follow. A template asks the database nothing. The chrome stores the menu's NAME, so a
     * menu deleted and made again under that name simply works, and nothing dangles when it
     * is not.
     *
     * NEITHER IS DRAWN EMPTY. A header with no logo, button or menu has nothing to show, and
     * an empty <header> on every page is noise. The footer's condition carries one extra
     * term: it owns the language switcher, so a site with two locales and no footer content
     * still needs its footer, or the switcher would vanish with it.
     *
     * @param array<int, array<string, mixed>> $locales
     * @return array{headerHtml: string, footerHtml: string}
     */
    private function chrome(string $locale, array $locales): array
    {
        $db = $this->container->get('db');
        $registry = $this->container->get('chrome');

        $menu = MenuTree::forVisitors($db, $locale, SiteChrome::menuName($db));
        $header = SiteChrome::header($db, $locale);
        $footer = SiteChrome::footer($db, $locale);

        // The logo is a picture like any other and has to be RESOLVED before the template
        // sees it, exactly as a block's pictures are. Handing the header an empty lookup
        // was a silent failure of my own making: the setting was saved, the template asked
        // for a tag, MediaPicture had no entry for that id and drew nothing at all. The
        // header simply had no logo, under every character, and nothing said why.
        $media = $header['logo'] === null ? [] : MediaPicture::resolve($db, $locale, [$header['logo']]);

        $hasHeader = $header['logo'] !== null || $header['button']['url'] !== '' || $menu !== [];
        $hasFooter = $footer['text'] !== '' || $footer['small_print'] !== '' || $menu !== [] || count($locales) > 1;

        return [
            'headerHtml' => $hasHeader
                ? $registry->render('header', $header, [], '', $media, true, 'header', ['menu' => $menu], $locale, $locales)
                : '',
            'footerHtml' => $hasFooter
                ? $registry->render('footer', $footer, [], '', [], false, 'footer', ['menu' => $menu], $locale, $locales)
                : '',
        ];
    }
}
