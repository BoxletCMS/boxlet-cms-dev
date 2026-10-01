<?php

namespace App\Modules\Appearance;

use App\Core\Container;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\Activity;
use App\Modules\Design\Characters;
use App\Modules\Design\Palette;
use App\Modules\Design\Presets;
use App\Modules\Design\Tokens;

/**
 * WHAT THE APPEARANCE SCREEN'S BUTTONS DO, short of publishing (PLAN.md D-059, D-157): load a
 * character, keep or bring back a design, give a colour back to the palette, put a control,
 * a section or everything back as the character has it.
 *
 * Split from AppearanceController with the screen (AppearanceScreen), so that one is left
 * with publishing. NONE OF THESE TOUCHES THE SITE: each answers with the screen in its new
 * state, and Publish is still the one confirmation. A redirect would hand back the
 * PUBLISHED design and lose the work on the screen.
 */
final class AppearanceActions
{
    public function __construct(private readonly Container $container, private readonly AppearanceScreen $screen)
    {
    }

    /**
     * The screen after one of these actions, or null for an action that is not one of them.
     *
     * @param array{decisions: array<string, string>, look: array<string, string>, menu: string, footer_menus: array<int, string>, words: array<string, array<string, mixed>>, errors: array<string, string>} $state
     * @param string $character the character loaded into the form, '' when none
     * @param string $basis what the screen measures against: that character, else the active one
     */
    public function handle(Request $request, string $action, array $state, string $character, string $basis): ?Response
    {
        // The answer "keep my changes" to the question a character load asks: the screen as
        // it was, and nothing else. An action this file did not know would be a publish.
        if ($action === 'keep') {
            return $this->screen->render($state, [], null, 200, $character);
        }
        if (str_starts_with($action, 'preset:') || str_starts_with($action, 'load:')) {
            return $this->load($action, $state, $character, $basis);
        }
        if (str_starts_with($action, 'library:')) {
            return $this->library($request, $action, $state, $character);
        }
        if (str_starts_with($action, 'colour:free')) {
            return $this->free($action, $state, $character);
        }
        if (str_starts_with($action, 'reset:')) {
            $reset = Overrides::reset($state['decisions'], $state['look'], substr($action, strlen('reset:')), $basis);
            if ($reset === null) {
                return $this->screen->render($state, [], null, 404, $character);
            }
            $again = Tokens::validate($reset['decisions']);

            return $this->screen->render(
                ['decisions' => $again['decisions'], 'look' => $reset['look']] + $state,
                $again['errors'],
                t($action === 'reset:all' ? 'inspector.reset.all_done' : 'inspector.reset.done'),
                200,
                $character,
            );
        }

        return null;
    }

    /**
     * LOADING A CHARACTER REPLACES THE DESIGN and keeps everything else the owner has typed:
     * their words are not a preference of the character's, and the look follows by itself.
     *
     * IT ASKS FIRST WHEN IT WOULD THROW SOMETHING AWAY (D-158). The decisions are stored as
     * values, so a character replaces every one — and the screen now shows which of them are
     * the owner's. Pressing a tile with seven dots on the screen and watching them vanish is
     * the one surprise the dots made possible, so the question says how many go. `load:` is
     * the answer; `preset:` with nothing to lose loads at once, as it always has.
     *
     * @param array{decisions: array<string, string>, look: array<string, string>, menu: string, footer_menus: array<int, string>, words: array<string, array<string, mixed>>, errors: array<string, string>} $state
     */
    private function load(string $action, array $state, string $character, string $basis): Response
    {
        $name = substr($action, (int) strpos($action, ':') + 1);
        if (!Presets::exists($name)) {
            return $this->screen->render($state, [], null, 404, '');
        }
        $lost = Overrides::lost($state['decisions'], $basis);
        if (str_starts_with($action, 'preset:') && $lost > 0) {
            return $this->screen->render($state, [], null, 200, $character, ['load' => ['character' => $name, 'count' => $lost]]);
        }

        return $this->screen->render(
            ['decisions' => Presets::get($name)] + $state,
            [],
            t('design.preset_loaded', ['preset' => Characters::label($name)]),
            200,
            $name,
        );
    }

    /**
     * GIVING A COLOUR BACK TO THE PALETTE (D-074), one, all, or the ones that fail (D-160).
     *
     * AN ACTION, NOT A BOX TO UNTICK. Each hand-set colour needs a switch beside it in the
     * form, because a colour input always carries SOME colour and "is this mine" cannot be
     * read off its value (D-065) — but that is a mechanism, not something to put in front of
     * a person. RE-VALIDATED, NOT TRUSTED: the palette's own colour can fail a pair the
     * owner's colour passed, and the screen must keep saying so (D-063).
     *
     * @param array{decisions: array<string, string>, look: array<string, string>, menu: string, footer_menus: array<int, string>, words: array<string, array<string, mixed>>, errors: array<string, string>} $state
     */
    private function free(string $action, array $state, string $character): Response
    {
        $decisions = $state['decisions'];
        $freed = 0;
        if ($action === 'colour:free:failing') {
            /* ONLY WHAT THE OWNER SET AND WHAT FAILS. A failing pair names the control that
               can fix it (Palette::pairs), and that is a colour by hand whenever one of its
               two colours is. Freed one at a time and measured again, because freeing the
               ink can be enough and the background the owner chose should then stay theirs.
               A pair the main colour fails is left: no colour by hand caused it, and the
               screen says to change the main colour instead. */
            while (($culprit = self::failingByHand($decisions)) !== null) {
                $decisions[$culprit] = '';
                $freed++;
            }
        } else {
            /* The seven palette roles and the three places that may take a colour of their
               own (D-076) are one list here: "Free all" frees all ten. */
            $named = $action === 'colour:free' ? null : substr($action, strlen('colour:free:'));
            foreach (array_merge(array_map(static fn (string $role): string => 'color_' . $role, Palette::BY_HAND), Tokens::OWN_COLOURS) as $field) {
                if ($named === null || $named === $field || $named === substr($field, strlen('color_'))) {
                    $decisions[$field] = '';
                    $freed++;
                }
            }
        }
        $again = Tokens::validate($decisions);

        return $this->screen->render(
            ['decisions' => $again['decisions']] + $state,
            $again['errors'],
            $freed === 0 ? null : t($freed > 1 ? 'design.by_hand.all_freed' : 'design.by_hand.freed'),
            200,
            $character,
        );
    }

    /**
     * The first colour by hand that a failing pair blames, or null when none does.
     *
     * @param array<string, string> $decisions
     */
    public static function failingByHand(array $decisions): ?string
    {
        $byHand = Tokens::byHand($decisions);
        $colors = Palette::colors($decisions['seed'], $decisions['secondary'], $decisions['surface_contrast'], $byHand);
        foreach (Palette::failures($colors, $decisions['secondary'] !== '', $byHand, Tokens::ownChrome($decisions)) as $failure) {
            if (Overrides::kind($failure['decision']) === 'by_hand' && ($decisions[$failure['decision']] ?? '') !== '') {
                return $failure['decision'];
            }
        }

        return null;
    }

    /**
     * The library (D-061): keep what is on the screen, bring one back, throw one away.
     *
     * @param array{decisions: array<string, string>, look: array<string, string>, menu: string, footer_menus: array<int, string>, words: array<string, array<string, mixed>>, errors: array<string, string>} $state
     */
    private function library(Request $request, string $action, array $state, string $character): Response
    {
        $db = $this->db();

        // Overwriting from a design's own row: the name comes from the design itself, so
        // "save what is on screen into this one" needs no field and cannot be mistyped.
        if (str_starts_with($action, 'library:save:')) {
            $into = DesignLibrary::find($db, (int) substr($action, strlen('library:save:')));
            if ($into === null) {
                return $this->screen->render($state, [], null, 404, $character);
            }
            DesignLibrary::save($db, $into['name'], $state['decisions'], $state['look'], $character);
            Activity::record($db, 'design', 'kept', null, $into['name']);

            return $this->screen->render($state, [], t('appearance.library.overwritten', ['name' => $into['name']]), 200, $character);
        }

        if ($action === 'library:save') {
            $name = DesignLibrary::cleanName($request->input('library_name'));
            if ($name === '') {
                return $this->screen->render($state, ['library_name' => t('appearance.library.name_needed')], null, 422, $character);
            }
            $written = DesignLibrary::exists($db, $name);
            DesignLibrary::save($db, $name, $state['decisions'], $state['look'], $character);
            Activity::record($db, 'design', 'kept', null, $name);

            return $this->screen->render($state, [], t($written ? 'appearance.library.overwritten' : 'appearance.library.saved', ['name' => $name]), 200, $character);
        }

        $id = (int) substr($action, (int) strrpos($action, ':') + 1);
        $saved = DesignLibrary::find($db, $id);
        if ($saved === null) {
            return $this->screen->render($state, [], null, 404, $character);
        }

        if (str_starts_with($action, 'library:delete:')) {
            DesignLibrary::delete($db, $id);
            Activity::record($db, 'design', 'deleted', null, $saved['name']);

            return $this->screen->render($state, [], t('appearance.library.deleted', ['name' => $saved['name']]), 200, $character);
        }

        // Using one fills the screen with it: the design AND the header and footer it was
        // kept with. The menu and the words stay the site's own.
        return $this->screen->render(
            ['decisions' => $saved['decisions'], 'look' => $saved['look']] + $state,
            [],
            t('appearance.library.loaded', ['name' => $saved['name']]),
            200,
            $saved['character'],
        );
    }

    private function db(): Db
    {
        return $this->container->get('db');
    }
}
