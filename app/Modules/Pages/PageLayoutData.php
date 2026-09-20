<?php

namespace App\Modules\Pages;

use App\Core\Container;
use App\Core\Settings;
use App\Modules\Media\MediaPicture;
use App\Modules\Menus\MenuTree;
use App\Modules\Settings\ChromeLook;
use App\Modules\Settings\SiteChrome;

/**
 * ONE SOURCE FOR EVERY VARIABLE Pages/views/layout.php READS (PLAN.md D-057).
 *
 * There are two renderers of the site's layout — a visitor's page, and the admin's design
 * preview — and until now the second answered "what does that layout need?" from a literal
 * array somebody had to remember to update. It was caught out three times: by `description`,
 * then by `icon`, then by the chrome of slice 5c. The comment above it said, correctly, that
 * the real fix is one source for these variables rather than a louder warning. This is it.
 *
 * Two locks, so there is no fourth time:
 *   - the declared return shape below. A factory that DROPS a key fails PHPStan before any
 *     test runs.
 *   - tests/page_layout_test.php reads the template itself and compares what it uses with
 *     KEYS. A variable the template GROWS fails there, by name. That is the half the test
 *     suite could not do on its own: it fails on any notice, but only along the branch some
 *     test happens to render, and `icon` sits behind an `if`.
 *
 * @phpstan-type LayoutData array{
 *     title: string,
 *     description: string,
 *     canonical: string|null,
 *     icon: array{url: string, type: string}|null,
 *     shareImage: string|null,
 *     hreflang: list<array{hreflang: string, href: string}>,
 *     headerHtml: string,
 *     footerHtml: string,
 * }
 */
final class PageLayoutData
{
    /**
     * What the layout is handed, beside View's own $locale and $content. The test above
     * asserts this list against the template, so it is a fact rather than a comment.
     */
    public const KEYS = ['title', 'description', 'canonical', 'icon', 'shareImage', 'hreflang', 'headerHtml', 'footerHtml'];

    /**
     * A visitor's page, or an error page.
     *
     * $head carries what only the caller knows — the title, and for a real page its
     * description, canonical address and sharing picture. The rest is the same for every
     * page and is assembled here: an error page carries the icon and the chrome too, because
     * a "not found" without the site's own header around it reads as a broken site rather
     * than a wrong address.
     *
     * @param array{title: string, description?: string, canonical?: string|null, shareImage?: string|null} $head
     * @param array<string, mixed>|null $page the page being drawn; null on an error page
     * @param string $current its address, for marking the menu; '' on an error page
     * @return LayoutData
     */
    public static function forPage(Container $container, string $locale, array $head, ?array $page = null, string $current = ''): array
    {
        $db = $container->get('db');
        // The languages as a visitor is offered them: this page in each, or that language's
        // home where it is not translated (D-043, step 4). What the switcher draws, and what
        // hreflang is made from.
        $alternates = Alternates::for($db, $page, $container->get('locales'));

        return [
            'title' => $head['title'],
            // An empty description is better than one repeating the title, so it stays empty
            // and the tag is dropped (D-004).
            'description' => $head['description'] ?? '',
            'canonical' => $head['canonical'] ?? null,
            // One indexed lookup per render, and null when no favicon is chosen.
            'icon' => SiteChrome::icon($db),
            // A link preview of an error page is not worth a row, so this is the caller's.
            'shareImage' => $head['shareImage'] ?? null,
            'hreflang' => Alternates::hreflang($alternates, self::primary($container->get('locales'))),
        ] + self::chrome($container, $locale, $alternates, $current, [], '');
    }

    /**
     * The admin's preview of a design.
     *
     * It draws the real chrome, which the preview deliberately did NOT do before: the old
     * screen existed to judge the tokens and section styles of one character, and chrome
     * around the specimen was furniture competing with the thing being looked at. On the
     * screen this is being built for, the header and footer are among the things being
     * judged, so the reason went with the old screen (PLAN.md D-057).
     *
     * $look carries chrome choices the owner is making and has not saved. $character is the
     * one being previewed, so a choice left at "follow the character" follows the character
     * on the screen rather than the one the site is published with.
     *
     * @param array<string, string> $look chrome choice => value, from the request
     * @param string $character the character being previewed; '' for the site's own
     * @return LayoutData
     */
    public static function forPreview(Container $container, string $locale, string $title, array $look = [], string $character = ''): array
    {
        $db = $container->get('db');

        return [
            'title' => $title,
            // A preview describes no page in particular, so it gives no description and the
            // layout emits no tag (D-004).
            'description' => '',
            'canonical' => null,
            'icon' => SiteChrome::icon($db),
            'shareImage' => null,
            // An admin address must never announce itself as a translation of anything.
            'hreflang' => [],
        ] + self::chrome($container, $locale, Alternates::for($db, null, $container->get('locales')), '', $look, $character);
    }

    /**
     * The site's header and footer, drawn by the block machinery so they inherit the design
     * tokens and the section style layers (PLAN.md D-028, D-030).
     *
     * THE MENU IS RESOLVED ONCE, here, and handed to both templates — the same rule pictures
     * follow. A template asks the database nothing. The chrome stores the menu's NAME, so a
     * menu deleted and made again under that name simply works, and nothing dangles when it
     * is not.
     *
     * NEITHER IS DRAWN EMPTY. A header with no logo, button or menu has nothing to show, and
     * an empty <header> on every page is noise. The footer's condition carries two extra
     * terms: it owns the language switcher, so a site with two locales and no footer content
     * still needs its footer, and it carries the Boxlet credit when that is switched on.
     *
     * THE CURRENT PAGE IS MARKED HERE, on the resolved menu, rather than handed to the
     * templates as an address to compare: which entry is this page is a resolution like any
     * other, and the templates stay free of URL arithmetic (PLAN.md D-032).
     *
     * @param array<int, array<string, mixed>> $locales the languages, as the switcher shows them
     * @param array<string, string> $look unsaved chrome choices; empty for a visitor's page
     * @return array{headerHtml: string, footerHtml: string}
     */
    private static function chrome(Container $container, string $locale, array $locales, string $current, array $look, string $character): array
    {
        $db = $container->get('db');
        $registry = $container->get('chrome');

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
        // Colour, arrangement and size: what the request is trying, else the owner's choice,
        // else the character's.
        $resolved = ChromeLook::resolve($db, $look, $character);
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
        // for a tag, MediaPicture had no entry for that id and drew nothing at all.
        $media = $header['logo'] === null ? [] : MediaPicture::resolve($db, $locale, [$header['logo']]);

        $hasHeader = $header['logo'] !== null || $header['button']['url'] !== '' || $menu !== [];
        // "Made with Boxlet", when the owner leaves it on (O-20). It is part of what makes a
        // footer worth drawing: a site whose footer is otherwise empty still has this line,
        // and without it here the credit would be switched on and never appear.
        $credit = Settings::get($db, 'site_credit') === true ? BOXLET_SITE : '';
        $hasFooter = $footer['text'] !== '' || $footer['small_print'] !== '' || $menu !== [] || count($locales) > 1 || $credit !== '';

        return [
            'headerHtml' => $hasHeader
                ? $registry->render('header', $header, ['surface' => $resolved['header_surface']], $resolved['header_layout'], $media, true, 'header', ['menu' => $menu, 'look' => $resolved], $locale, $locales)
                : '',
            'footerHtml' => $hasFooter
                ? $registry->render('footer', $footer, ['surface' => $resolved['footer_surface']], $resolved['footer_layout'], [], false, 'footer', ['menu' => $menu, 'look' => $resolved, 'credit' => $credit], $locale, $locales)
                : '',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $locales
     */
    private static function primary(array $locales): string
    {
        foreach ($locales as $each) {
            if ((int) $each['is_primary'] === 1) {
                return (string) $each['code'];
            }
        }

        return '';
    }
}
