<?php

namespace App\Modules\Pages;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Forms\FormBlocks;
use App\Modules\Media\MediaPicture;
use App\Modules\Menus\MenuTree;
use App\Modules\Settings\ChromeLook;
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
        // Links to pages, followed in one query in the visitor's language (PLAN.md D-034).
        $links = PageLinks::targets($db, $registry, $locale, $blocks);
        // Forms, with what the visitor just did to one (D-046): ?sent=id after a send.
        $sent = $request->query['sent'] ?? null;
        $forms = FormBlocks::resolve(
            $db,
            $blocks,
            $locale,
            (int) $page['id'],
            (string) $this->container->get('config')->get('app.key'),
            is_string($sent) && ctype_digit($sent) ? (int) $sent : null,
        );

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
            $content = PageLinks::content($registry, $block['type'], $block['content'], $links);
            $html .= $registry->render($block['type'], $content, $block['style'], $block['layout'], $media, $first, 'section', ['forms' => $forms], $locale);
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
        ], 200, Url::page($locale, $slug), $page);
    }

    /**
     * The sitemap as a route, for a host where public/sitemap.xml cannot be written
     * (D-049). The file is what search engines are pointed at wherever it exists.
     *
     * @param array<string, string> $params
     */
    public function sitemap(Request $request, string $locale, array $params): Response
    {
        return new Response(Sitemap::xml($this->container->get('db')), 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    /**
     * @param array<string, string> $params
     */
    public function notFound(Request $request, string $locale, array $params): Response
    {
        return $this->render('404', $locale, [
            'title' => site_t('site.not_found.title', $locale),
            'intro' => site_t('site.not_found.intro', $locale),
        ], 404);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $page the page being drawn; null on an error page
     */
    private function render(string $template, string $locale, array $data, int $status = 200, string $current = '', ?array $page = null): Response
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
        // The languages as a visitor is offered them: this page in each, or that
        // language's home where it is not translated (D-043, step 4). What the switcher
        // draws, and what hreflang is made from.
        $alternates = Alternates::for($db, $page, $this->container->get('locales'));
        $primary = '';
        foreach ($this->container->get('locales') as $each) {
            if ((int) $each['is_primary'] === 1) {
                $primary = (string) $each['code'];
            }
        }

        $data += [
            'canonical' => null,
            'description' => '',
            'icon' => SiteChrome::icon($db),
            'shareImage' => null,
            'locales' => $alternates,
            'hreflang' => Alternates::hreflang($alternates, $primary),
        ] + $this->chrome($locale, $alternates, $current);

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
     * THE CURRENT PAGE IS MARKED HERE, on the resolved menu, rather than handed to the
     * templates as an address to compare: which entry is this page is a resolution like any
     * other, and the templates stay free of URL arithmetic (PLAN.md D-032). A parent learns
     * that the page is one of its children, so a visitor can see where they are.
     *
     * @param array<int, array<string, mixed>> $locales
     * @param string $current the address of the page being drawn; '' on an error page
     * @return array{headerHtml: string, footerHtml: string}
     */
    private function chrome(string $locale, array $locales, string $current = ''): array
    {
        $db = $this->container->get('db');
        $registry = $this->container->get('chrome');

        $menu = [];
        foreach (MenuTree::forVisitors($db, $locale, SiteChrome::menuName($db)) as $item) {
            $children = [];
            $below = false;
            foreach ($item['children'] as $child) {
                $children[] = $child + ['current' => $current !== '' && $child['url'] === $current];
                $below = $below || ($current !== '' && $child['url'] === $current);
            }
            $menu[] = ['children' => $children, 'current' => $current !== '' && $item['url'] === $current, 'current_parent' => $below] + $item;
        }
        // Colour, arrangement and size: the owner's choices, the character's for the rest.
        $look = ChromeLook::resolve($db);
        // The header's button can point at a page like any link field (D-034); followed
        // here, before the check below asks whether it leads anywhere.
        $header = SiteChrome::header($db, $locale);
        $header['button'] = PageLinks::link(
            $header['button'],
            PageLinks::targets($db, $registry, $locale, [['type' => 'header', 'content' => $header]]),
        );
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
                ? $registry->render('header', $header, ['surface' => $look['header_surface']], $look['header_layout'], $media, true, 'header', ['menu' => $menu, 'look' => $look], $locale, $locales)
                : '',
            'footerHtml' => $hasFooter
                ? $registry->render('footer', $footer, ['surface' => $look['footer_surface']], $look['footer_layout'], [], false, 'footer', ['menu' => $menu, 'look' => $look], $locale, $locales)
                : '',
        ];
    }
}
