<?php

namespace App\Modules\Appearance;

use App\Core\Container;
use App\Core\Db;
use App\Core\Response;
use App\Modules\Admin\AdminView;
use App\Modules\Design\Characters;
use App\Modules\Design\Composition;
use App\Modules\Design\Design;
use App\Modules\Design\Palette;
use App\Modules\Design\Tokens;
use App\Modules\Menus\Menu;
use App\Modules\Pages\PageLinks;
use App\Modules\Pages\PageTree;
use App\Modules\Settings\ChromeLook;
use App\Modules\Settings\ChromeWords;
use App\Modules\Settings\SiteChrome;
use App\Support\Url;

/**
 * THE APPEARANCE SCREEN AS DRAWN (PLAN.md D-059, D-157): everything its view needs, worked
 * out from one state of the form.
 *
 * Split from AppearanceController, which had grown to 444 lines holding three things: what
 * the screen shows, what its buttons do, and publishing. This is the first. Every answer the
 * screen gives — a character loaded, a reset, a refused publish, an import waiting — is this
 * one drawing of a different state, which is why the controller and the transfer controller
 * both hand their state here rather than render a view of their own.
 */
final class AppearanceScreen
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * What the site is published with: the state the screen opens on.
     *
     * @return array{decisions: array<string, string>, look: array<string, string>, menu: string, footer_menus: array<int, string>, words: array<string, array<string, mixed>>}
     */
    public function published(): array
    {
        $db = $this->db();

        return [
            'decisions' => Design::load($db),
            'look' => ChromeLook::stored($db),
            'menu' => SiteChrome::menuName($db),
            'footer_menus' => SiteChrome::footerMenus($db),
            'words' => ChromeWords::stored($db, $this->locales()),
        ];
    }

    /**
     * The published screen with what became of a design file brought in: the set and its
     * warnings, waiting for an answer, or the reasons it was refused (D-152).
     *
     * @param array{set: array<string, mixed>, warnings: list<string>}|null $import
     * @param list<string> $importErrors
     */
    public function withImport(?array $import, array $importErrors): Response
    {
        return $this->render($this->published(), [], null, $importErrors === [] ? 200 : 422, '', ['import' => $import, 'importErrors' => $importErrors]);
    }

    /**
     * @param array{decisions: array<string, string>, look: array<string, string>, menu: string, footer_menus?: array<int, string>, words: array<string, array<string, mixed>>} $state
     * @param array<string, string> $errors
     * @param string $character the character loaded into the form, if any
     * @param array{confirm?: bool, replaces?: int, load?: array{character: string, count: int}, import?: array{set: array<string, mixed>, warnings: list<string>}|null, importErrors?: list<string>} $extra
     *        a question waiting for an answer: how to publish a character (confirm), whether
     *        to load one over the owner's changes (load), what to do with an imported file
     */
    public function render(array $state, array $errors, ?string $notice, int $status = 200, string $character = '', array $extra = []): Response
    {
        $db = $this->db();
        $decisions = $state['decisions'];
        $byHand = Tokens::byHand($decisions);
        $colors = Palette::colors($decisions['seed'], $decisions['secondary'], $decisions['surface_contrast'], $byHand);
        $pairs = Palette::pairs($colors, $decisions['secondary'] !== '', $byHand, Tokens::ownChrome($decisions));
        $shown = Url::primaryLocale() !== '' ? Url::primaryLocale() : ($this->locales()[0] ?? 'en');
        $active = Composition::active($db);
        // WHAT THE SCREEN MEASURES AGAINST (D-158): the character loaded into it, else the one
        // the site was composed with. Every dot, count and reset on the screen reads this one.
        $basis = $character !== '' ? $character : $active;
        $characterLook = Characters::look($basis);

        return AdminView::render($this->container, __DIR__ . '/views', 'appearance', [
            'title' => t('appearance.title'),
            'nav' => 'appearance',
            // ORDER IS LOAD-BEARING FOR THE LAST ONE. -widths.css holds every threshold at
            // which this screen rearranges, and several of those override a base rule of the
            // same specificity in the files before it, so the cascade is decided here (D-072).
            'styles' => [
                // The rich text editor's own, first: the footer's text is rich text (D-113).
                'admin-richtext.css',
                'admin-appearance.css',
                'admin-appearance-home.css',
                'admin-appearance-tiles.css',
                'admin-appearance-picture.css',
                'admin-appearance-sections.css',
                'admin-appearance-inspector.css',
                'admin-appearance-colour.css',
                'admin-appearance-contrast.css',
                'admin-appearance-layout.css',
                'admin-appearance-widths.css',
            ],
            // TipTap and the field script that binds it, the same pair the page editor loads.
            // A design file is sent when it is chosen (file-sends.js, D-152).
            'scripts' => ['vendor/tiptap.bundle.min.js', 'richtext.js', 'file-sends.js'],
            // The screen IS the window, as the page editor's canvas is: the admin's rail
            // folds to its icons beside it (D-064).
            'bare' => true,
            'decisions' => $decisions,
            'errors' => $errors,
            'notice' => $notice,
            'character' => $character,
            'basis' => $basis,
            'confirm' => $extra['confirm'] ?? false,
            'replaces' => $extra['replaces'] ?? 0,
            'load' => $extra['load'] ?? null,
            'activeCharacter' => $active,
            'hasBlocks' => Composition::hasBlocks($db),
            'library' => DesignLibrary::all($db),
            'colors' => $colors,
            'pairs' => $pairs,
            'readable' => Tokens::readable($decisions),
            'readouts' => AppearanceForm::readouts($decisions)
                + SectionSummaries::of($decisions, SectionSummaries::answered($state['look'], $characterLook), $pairs),
            // What the owner has made theirs over that character, and what each control is
            // when they have not (D-158).
            'defaults' => Overrides::defaults($basis),
            'changed' => Overrides::changed($decisions, $state['look'], $basis),
            // The chrome half of the screen.
            'look' => $state['look'],
            'menu' => $state['menu'],
            'footerMenus' => $state['footer_menus'] ?? SiteChrome::footerMenus($db),
            'menus' => self::menuNames($db),
            'words' => $state['words'],
            'locales' => $this->container->get('locales'),
            'shownLocale' => $shown,
            'characterLook' => $characterLook,
            // What the button may point at, per language: a Croatian header links to
            // Croatian pages (D-034).
            'linkPages' => array_combine($this->locales(), array_map(
                static fn (string $code): array => PageLinks::choices($db, $code),
                $this->locales(),
            )),
            // What the strip over the picture says is in the frame.
            'host' => (string) parse_url(Url::withOrigin(''), PHP_URL_HOST),
            'pageName' => self::previewedPage($db, $shown),
            'previewPages' => self::previewPages($db, $shown),
            'previewUrl' => Url::withQuery(Url::admin('appearance', 'preview'), AppearanceForm::query($state, $shown, $character)),
            // Design files (D-152): one brought in and waiting, why one was refused, the custom
            // files left out, and a character the site was composed with that is gone (D-156).
            'import' => $extra['import'] ?? null,
            'importErrors' => $extra['importErrors'] ?? [],
            'skipped' => Characters::skipped(),
            'missingCharacter' => Composition::missing($db),
        ], $status);
    }

    /**
     * array_values, because array_map over the container's locales keeps that array's keys
     * and a list is what this promises.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        return array_values(array_map(
            static fn (array $locale): string => (string) $locale['code'],
            $this->container->get('locales'),
        ));
    }

    /**
     * The menu names on offer, each once. The same name in two languages is one choice:
     * that is the point of storing a name rather than an id.
     *
     * @return list<string>
     */
    public static function menuNames(Db $db): array
    {
        $names = [];
        foreach (Menu::all($db) as $menu) {
            $names[$menu['name']] = true;
        }

        return array_keys($names);
    }

    /**
     * What the preview is a picture OF: the home page by name, or the specimen when a site
     * has no home page yet. The strip says so, because a preview with no address is a
     * picture of something.
     */
    private static function previewedPage(Db $db, string $locale): string
    {
        $home = $db->one('SELECT title FROM pages WHERE slug = ? AND locale = ?', ['', $locale]);

        return $home === null ? t('design.preview') : (string) $home['title'];
    }

    /**
     * The published pages the picture can be of, in the tree's order with the home page
     * first (D-111): a header laid over the first section looks different over a page with
     * no hero, and a sticky header cannot be judged on a short one.
     *
     * @return list<array{id: int, title: string, depth: int}>
     */
    private static function previewPages(Db $db, string $locale): array
    {
        $status = [];
        foreach ($db->all('SELECT id, status FROM pages WHERE locale = ?', [$locale]) as $row) {
            $status[(int) $row['id']] = (string) $row['status'];
        }
        $pages = [];
        foreach (PageTree::parentOptions($db, $locale, null) as $option) {
            if (($status[(int) $option['id']] ?? '') === 'published') {
                $pages[] = ['id' => (int) $option['id'], 'title' => (string) $option['title'], 'depth' => (int) $option['depth']];
            }
        }

        return $pages;
    }

    private function db(): Db
    {
        return $this->container->get('db');
    }
}
