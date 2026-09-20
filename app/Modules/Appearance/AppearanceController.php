<?php

namespace App\Modules\Appearance;

use App\Core\Container;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\Activity;
use App\Modules\Admin\AdminView;
use App\Modules\Design\Composition;
use App\Modules\Design\Design;
use App\Modules\Design\Palette;
use App\Modules\Design\Presets;
use App\Modules\Design\Tokens;
use App\Modules\Menus\Menu;
use App\Modules\Pages\PageLinks;
use App\Modules\Settings\ChromeLook;
use App\Modules\Settings\ChromeWords;
use App\Modules\Settings\SiteChrome;
use App\Support\Url;

/**
 * ONE SCREEN FOR HOW THE SITE LOOKS (PLAN.md D-059): the character, the ten decisions, the
 * header and footer, and a picture of all of it.
 *
 * It replaces two screens that were one screen cut in half. Header & footer had seven
 * choices and NO PICTURE; Design had a picture that deliberately drew no header or footer.
 * Each was incomplete in exactly the way the other would have fixed, and the owner had to
 * hold the result in their head while moving between them.
 *
 * Saving is still one ordinary form post that works without JavaScript, and applying a
 * character to a site that already has blocks still offers two explicit buttons, because
 * the second overwrites section styles chosen by hand (SPEC §5.4).
 */
final class AppearanceController
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, string $locale, array $params): Response
    {
        $db = $this->db();

        return $this->screen([
            'decisions' => Design::load($db),
            'look' => ChromeLook::stored($db),
            'menu' => SiteChrome::menuName($db),
            'words' => ChromeWords::stored($db, $this->locales()),
        ], [], null);
    }

    /**
     * An old address, kept while bookmarks and habits catch up (D-059). The screens merged;
     * their addresses have not been taken away in the same breath.
     *
     * @param array<string, string> $params
     */
    public function moved(Request $request, string $locale, array $params): Response
    {
        return Response::redirect(Url::admin('appearance'));
    }

    /**
     * Publish, or load a character into the form. A loaded character changes nothing on the
     * site until it is published: Publish is the confirmation.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, string $locale, array $params): Response
    {
        $action = $request->input('action');
        $state = AppearanceForm::read($request, $this->locales());

        // Loading a character replaces the DESIGN and keeps everything else the owner has
        // typed: their words are not a preference of the character's.
        if (str_starts_with($action, 'preset:') && Presets::exists(substr($action, 7))) {
            $name = substr($action, 7);

            return $this->screen(
                ['decisions' => Presets::get($name)] + $state,
                [],
                t('design.preset_loaded', ['preset' => t('design.preset.' . $name)]),
                200,
                $name,
            );
        }

        $character = $request->input('character');
        $character = Presets::exists($character) ? $character : '';
        if ($state['errors'] !== []) {
            return $this->screen($state, $state['errors'], t('design.not_saved'), 422, $character);
        }

        $db = $this->db();
        // A menu is chosen by name, and a name no menu carries any more is cleared rather
        // than stored: the header would render nothing for it, and a setting that silently
        // means nothing is worse than an empty one the owner can see.
        $goneMenu = $state['menu'] !== '' && !in_array($state['menu'], self::menuNames($db), true);

        Design::save($db, $state['decisions'], (string) $this->container->get('config')->get('app.cache_path'));
        SiteChrome::saveShared($db, $goneMenu ? '' : $state['menu']);
        ChromeLook::save($db, $state['look']);
        ChromeWords::save($db, $state['words']);

        $message = t('appearance.published');
        if ($character !== '') {
            Composition::remember($db, $character);
            if ($action === 'save_composition') {
                $count = Composition::apply($db, $this->container->get('blocks'), $character);
                $message = t('design.saved_with_composition', [
                    'count' => $count,
                    'character' => t('design.preset.' . $character),
                ]);
            }
        }
        if ($goneMenu) {
            $message .= ' ' . t('chrome.menu_gone');
        }
        Activity::record($db, 'design', 'saved', null, $character !== '' ? t('design.preset.' . $character) : '');
        $session = $this->container->get('session');
        $session->set('flash', $message);
        $session->set('flash_kind', $goneMenu ? 'warning' : 'success');

        return Response::redirect(Url::admin('appearance'));
    }

    /**
     * @param array{decisions: array<string, string>, look: array<string, string>, menu: string, words: array<string, array<string, string>>} $state
     * @param array<string, string> $errors
     * @param string $character the character loaded into the form, if any
     */
    private function screen(array $state, array $errors, ?string $notice, int $status = 200, string $character = ''): Response
    {
        $db = $this->db();
        $decisions = $state['decisions'];
        $colors = Palette::colors($decisions['seed'], $decisions['secondary'], $decisions['surface_contrast']);
        $shown = Url::primaryLocale() !== '' ? Url::primaryLocale() : ($this->locales()[0] ?? 'en');

        return AdminView::render($this->container, __DIR__ . '/views', 'appearance', [
            'title' => t('appearance.title'),
            'nav' => 'appearance',
            'styles' => ['admin-design.css', 'admin-appearance.css'],
            'wide' => true,
            'decisions' => $decisions,
            'errors' => $errors,
            'notice' => $notice,
            'character' => $character,
            'activeCharacter' => Composition::active($db),
            'hasBlocks' => Composition::hasBlocks($db),
            'colors' => $colors,
            'pairs' => Palette::pairs($colors, $decisions['secondary'] !== ''),
            'readable' => Tokens::readable($decisions),
            // The chrome half of the screen.
            'look' => $state['look'],
            'menu' => $state['menu'],
            'menus' => self::menuNames($db),
            'words' => $state['words'],
            'locales' => $this->container->get('locales'),
            'shownLocale' => $shown,
            // What "as the character has it" means right now, so each choice can say it.
            'characterLook' => ChromeLook::CHARACTER[$character !== '' ? $character : Composition::active($db)] ?? ChromeLook::CHARACTER['minimal'],
            // What the button may point at, per language: a Croatian header links to
            // Croatian pages (D-034).
            'linkPages' => array_combine($this->locales(), array_map(
                static fn (string $code): array => PageLinks::choices($db, $code),
                $this->locales(),
            )),
            'previewUrl' => Url::withQuery(Url::admin('appearance', 'preview'), AppearanceForm::query($state, $shown, $character)),
        ], $status);
    }

    /**
     * The menu names on offer, each once. The same name in two languages is one choice:
     * that is the point of storing a name rather than an id.
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
     * array_values, because array_map over the container's locales keeps that array's keys
     * and a list is what this promises.
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

    private function db(): Db
    {
        return $this->container->get('db');
    }
}
