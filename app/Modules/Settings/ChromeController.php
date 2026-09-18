<?php

namespace App\Modules\Settings;

use App\Core\Container;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\AdminView;
use App\Modules\Media\MediaReference;
use App\Modules\Menus\Menu;
use App\Modules\Design\Composition;
use App\Modules\Pages\PageLinks;
use App\Support\SafeUrl;
use App\Support\Url;

/**
 * The header and footer of the whole site (PLAN.md D-028, D-030), on their own screen.
 *
 * Beside site settings rather than inside it: settings are what the installer wrote and
 * the site's fallback pictures, while this screen is what a visitor sees at the top and
 * bottom of every page. One screen holding both would be a long page where the important
 * half is whichever one you did not come for.
 *
 * No new table and no block rows. The choices are settings, read back by SiteChrome and
 * handed to the chrome templates by PageController — which is why nothing here composes a
 * settings key itself. Field names go in; the shape of the key is SiteChrome's alone, and
 * chrome_test.php fails if anywhere else spells one out.
 *
 * WHAT IS PER LANGUAGE AND WHAT IS NOT. The logo is one picture and the menu is one name;
 * a name resolves to each locale's own menu, so it does not repeat. The owner's words do:
 * a Croatian page with English small print is the kind of thing only a visitor notices.
 */
final class ChromeController
{
    /*
     * FORM FIELD NAMES ARE NOT SETTINGS KEYS, and they no longer look alike.
     *
     * The first version called these chrome_logo, chrome_menu and chrome_button_label_<code>
     * — the same prefix SiteChrome uses for the keys it stores. chrome_test.php refuses any
     * `chrome_` literal outside SiteChrome, and it failed on this file, correctly: two
     * different namespaces had grown one prefix, so neither the guard nor a reader could
     * tell a posted field from a stored key. Renaming made the difference real instead of
     * teaching the guard to tolerate it.
     */
    private const LOGO = 'header_logo';
    private const MENU = 'header_menu';

    /** The words the owner writes, once per enabled language; posted as <field>_<locale>. */
    private const PER_LOCALE = [
        'button_label' => 'header_button_label',
        'button_url' => 'header_button_url',
        'text' => 'footer_text',
        'small_print' => 'footer_small_print',
    ];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, string $locale, array $params): Response
    {
        return $this->form($this->stored(), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function save(Request $request, string $locale, array $params): Response
    {
        $db = $this->db();

        // An id that names no picture becomes null — the rule MediaReference sets for block
        // content, and the one site settings follows. Nothing stops a library row being
        // deleted after it was chosen here.
        $known = array_column(MediaReference::choices($db), 'id');
        $logo = (int) $request->input(self::LOGO);
        $clearedLogo = $logo > 0 && !in_array($logo, $known, true);

        // A menu is chosen by name, and a name that no menu carries any more is cleared
        // rather than stored: the header would render nothing for it, and a setting that
        // silently means nothing is worse than an empty one the owner can see.
        $menu = trim($request->input(self::MENU));
        $clearedMenu = $menu !== '' && !in_array($menu, self::menuNames($db), true);

        $errors = [];
        $values = [];
        foreach ($this->locales() as $code) {
            // Written out rather than built in a loop: saveForLocale() takes all four keys,
            // and a loop only ever establishes "these keys might be present" — which is a
            // promise asserted at the boundary and proved nowhere, exactly what PHPStan
            // objected to here.
            $entry = [
                'button_label' => trim($request->input(self::field('button_label', $code))),
                'button_url' => trim($request->input(self::field('button_url', $code))),
                'text' => trim($request->input(self::field('text', $code))),
                'small_print' => trim($request->input(self::field('small_print', $code))),
            ];
            // A chosen page wins over a typed address, as in a block's link field (PLAN.md
            // D-034); the address input is hidden while a page is chosen.
            $page = trim($request->input(self::field('button_page', $code)));
            if (preg_match('~^[1-9][0-9]{0,9}$~', $page) === 1) {
                $entry['button_url'] = PageLinks::to((int) $page);
            }

            // The same guard the menu builder uses for an item's address: an address that
            // is not one we would follow is refused here rather than written into every
            // page's header. Both halves of a button are needed, or it is not a link.
            if ($entry['button_url'] !== '' && !SafeUrl::isLink($entry['button_url'])) {
                $errors[self::field('button_url', $code)] = t('chrome.button_url_refused');
            }
            $values[$code] = $entry;
        }

        // The look (D-032, D-036): each a closed set, '' for "as the character has it".
        // Posted as look_<choice>, never chrome_*: a form field is not a settings key.
        $look = [];
        foreach (array_keys(ChromeLook::OPTIONS) as $choice) {
            $look[$choice] = trim($request->input('look_' . $choice));
        }

        if ($errors !== []) {
            return $this->form([
                'logo' => $logo > 0 ? $logo : null,
                'menu' => $menu,
                'locales' => $values,
                'look' => $look,
            ], $errors, 422);
        }

        SiteChrome::saveShared($db, $clearedLogo || $logo <= 0 ? null : $logo, $clearedMenu ? '' : $menu);
        ChromeLook::save($db, $look);
        foreach ($values as $code => $entry) {
            SiteChrome::saveForLocale($db, $code, $entry);
        }

        $said = t('chrome.saved');
        if ($clearedLogo) {
            $said .= ' ' . t('chrome.logo_gone');
        }
        if ($clearedMenu) {
            $said .= ' ' . t('chrome.menu_gone');
        }
        $session = $this->container->get('session');
        $session->set('flash', $said);
        $session->set('flash_kind', $clearedLogo || $clearedMenu ? 'warning' : 'success');

        return Response::redirect(Url::admin('chrome'));
    }

    /**
     * Everything the screen shows: the shared choices, and one group per enabled language.
     *
     * @return array{logo: int|null, menu: string, locales: array<string, array<string, string>>, look: array<string, string>}
     */
    private function stored(): array
    {
        $db = $this->db();
        $perLocale = [];
        foreach ($this->locales() as $code) {
            $header = SiteChrome::header($db, $code);
            $footer = SiteChrome::footer($db, $code);
            $perLocale[$code] = [
                'button_label' => $header['button']['label'],
                'button_url' => $header['button']['url'],
                'text' => $footer['text'],
                'small_print' => $footer['small_print'],
            ];
        }

        return [
            'logo' => SiteChrome::header($db, $this->locales()[0] ?? 'en')['logo'],
            'menu' => SiteChrome::menuName($db),
            'locales' => $perLocale,
            'look' => ChromeLook::stored($db),
        ];
    }

    /**
     * The menu names on offer, each once. The same name in two languages is one choice
     * here: that is the point of storing a name rather than an id.
     *
     * @return list<string>
     */
    private static function menuNames(Db $db): array
    {
        $names = [];
        foreach (Menu::all($db) as $menu) {
            $names[$menu['name']] = true;
        }

        return array_keys($names);
    }

    /**
     * The posted name of one per-language field. The view and the controller must agree on
     * it exactly, so it is composed once here rather than spelled out in both.
     */
    public static function field(string $name, string $locale): string
    {
        return (self::PER_LOCALE[$name] ?? $name) . '_' . $locale;
    }

    /**
     * array_values, because array_map over the container's locales keeps that array's keys
     * and a list is what this promises. Not a cast to quieten the checker: the promise is
     * either true of the value or it is not.
     *
     * @return list<string>
     */
    private function locales(): array
    {
        return array_values(array_map(
            static fn (array $locale): string => (string) $locale['code'],
            $this->container->get('locales'),
        ));
    }

    /**
     * @param array{logo: int|null, menu: string, locales: array<string, array<string, string>>, look: array<string, string>} $values
     * @param array<string, string> $errors
     */
    private function form(array $values, array $errors, int $status = 200): Response
    {
        $db = $this->db();

        return AdminView::render($this->container, __DIR__ . '/views', 'chrome', [
            'title' => t('chrome.title'),
            'nav' => 'chrome',
            'styles' => ['admin-media.css', 'admin-picker.css'],
            'scripts' => ['media-picker.js'],
            'values' => $values,
            'errors' => $errors,
            'pictures' => MediaReference::choices($db),
            'menus' => self::menuNames($db),
            'locales' => $this->container->get('locales'),
            // What "as the character has it" means right now, so each choice can say it.
            'characterLook' => ChromeLook::CHARACTER[Composition::active($db)] ?? ChromeLook::CHARACTER['minimal'],
            // What the button may point at, per language: a Croatian header links to
            // Croatian pages (D-034).
            'linkPages' => array_combine($this->locales(), array_map(
                static fn (string $code): array => PageLinks::choices($db, $code),
                $this->locales(),
            )),
        ], $status);
    }

    private function db(): Db
    {
        return $this->container->get('db');
    }
}
