<?php

namespace App\Modules\Appearance;

use App\Modules\Design\Characters;
use App\Modules\Settings\ChromeLook;

/**
 * WHAT THE OWNER HAS MADE THEIR OWN, over the character the screen is showing (PLAN.md
 * D-158), and how the Appearance screen is cut into sections and groups (D-157).
 *
 * THREE KINDS OF CONTROL, three meanings of "changed", because the three are stored three
 * ways and only one of them can be read off a comparison:
 *
 * - a DECISION (spacing, the scale, the sheet's width…) always holds a value, and loading a
 *   character replaces all of them. Changed means "not what the character gives"; putting
 *   it back means writing the character's value. The stored model is untouched in this
 *   phase: '' for a decision is phase 2's (O-41).
 * - a colour BY HAND (a role of the palette, or a place's own colour) is '' while the
 *   palette decides. Changed means "set by hand" — not "unlike the character", because the
 *   value it is compared with would be a colour the palette worked out and the dot is about
 *   who chose it; putting it back is giving it to the palette (D-074).
 * - a LOOK choice for the header or footer is '' while the character decides. Changed means
 *   set AND unlike the character's; putting it back is ''. A choice equal to the character's
 *   is stored as '' (settle()), so there is one way to say "as the character has it".
 *
 * One class rather than three places that each know a third, because the dot, the counts,
 * the banner, the three resets and the question before a character is loaded all have to
 * agree about which keys moved — and a test can then ask it once.
 */
final class Overrides
{
    /**
     * The sections in the order the home lists them, each a list of groups and each group the
     * keys it holds. Every decision and every look choice is in exactly one place
     * (appearance_inspector_test). A group with no keys holds something that is not a
     * decision: the contrast check, the diagram, a menu, the words.
     */
    public const SECTIONS = [
        'colours' => [
            'basics' => ['seed', 'secondary', 'surface_contrast'],
            'palette' => ['color_background', 'color_card', 'color_surface', 'color_border', 'color_text', 'color_muted', 'color_link'],
            'contrast' => [],
        ],
        'typography' => [
            'typeface' => ['typography'],
            'sizes' => ['text_size', 'scale'],
            'headings' => ['heading_weight', 'tracking', 'caps'],
            'fine' => ['nudge_h1', 'nudge_h2', 'nudge_sm'],
        ],
        'space' => [
            'spacing' => ['spacing'],
            'shape' => ['radius'],
            'shadow' => ['shadow'],
        ],
        'layout' => [
            'diagram' => [],
            'content' => ['container'],
            'page' => ['boxed', 'sheet_width', 'frame', 'sheet_gap', 'sheet_radius', 'sheet_shadow', 'page_background', 'page_background_colour'],
            'chrome' => ['header_bleed', 'header_width', 'footer_bleed', 'footer_width'],
        ],
        'header' => [
            'arrangement' => ['header_arrangement', 'brand', 'logo_size', 'density'],
            'behaviour' => ['header_behaviour'],
            'background' => ['header_surface', 'header_colour', 'header_edge'],
            'menu' => ['nav_style', 'nav_ink', 'header_button'],
            'words' => [],
        ],
        'footer' => [
            'arrangement' => ['footer_layout', 'footer_columns', 'footer_links', 'small_print_row'],
            'menus' => [],
            'background' => ['footer_surface', 'footer_colour', 'footer_edge'],
            'words' => [],
        ],
    ];

    /** The colours that are '' until set by hand: the palette's roles and the three places. */
    private const BY_HAND = [
        'color_background', 'color_card', 'color_surface', 'color_border', 'color_text', 'color_muted', 'color_link',
        'page_background_colour', 'header_colour', 'footer_colour',
    ];

    /** 'decision', 'by_hand' or 'look'. */
    public static function kind(string $key): string
    {
        if (isset(ChromeLook::OPTIONS[$key])) {
            return 'look';
        }

        return in_array($key, self::BY_HAND, true) ? 'by_hand' : 'decision';
    }

    /**
     * What each control is when the owner has not made it theirs: the character's decision,
     * '' for a colour by hand (the palette's), the character's look choice. Printed as
     * data-default, and what a reset writes — except a look, whose reset is ''.
     *
     * @return array<string, string>
     */
    public static function defaults(string $character): array
    {
        $decisions = Characters::decisions($character);
        $look = Characters::look($character);
        $defaults = [];
        foreach (self::keys('all') ?? [] as $key) {
            $defaults[$key] = match (self::kind($key)) {
                'look' => $look[$key] ?? '',
                'by_hand' => '',
                default => $decisions[$key] ?? '',
            };
        }

        return $defaults;
    }

    /**
     * The keys that differ from what the character gives, in the order of SECTIONS.
     *
     * @param array<string, string> $decisions
     * @param array<string, string> $look
     * @return list<string>
     */
    public static function changed(array $decisions, array $look, string $character): array
    {
        $defaults = self::defaults($character);
        $changed = [];
        foreach ($defaults as $key => $default) {
            $mine = match (self::kind($key)) {
                'look' => ($look[$key] ?? '') !== '' && $look[$key] !== $default,
                'by_hand' => ($decisions[$key] ?? '') !== '',
                default => !self::same($decisions[$key] ?? '', $default),
            };
            if ($mine) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * How many of $changed fall in a section, or in one of its groups.
     *
     * @param list<string> $changed
     */
    public static function count(array $changed, string $section, ?string $group = null): int
    {
        $keys = $group === null
            ? array_merge(...array_values(self::SECTIONS[$section] ?? [[]]))
            : (self::SECTIONS[$section][$group] ?? []);

        return count(array_intersect($changed, $keys));
    }

    /**
     * The keys a reset reaches: `all`, `section:<name>`, or one key. Null for a scope that
     * names nothing, so a forged action is refused rather than read as "nothing to do".
     *
     * @return list<string>|null
     */
    public static function keys(string $scope): ?array
    {
        if ($scope === 'all') {
            $keys = [];
            foreach (self::SECTIONS as $groups) {
                foreach ($groups as $inGroup) {
                    array_push($keys, ...$inGroup);
                }
            }

            return $keys;
        }
        if (str_starts_with($scope, 'section:')) {
            $groups = self::SECTIONS[substr($scope, strlen('section:'))] ?? null;

            return $groups === null ? null : array_merge(...array_values($groups));
        }

        return in_array($scope, self::keys('all') ?? [], true) ? [$scope] : null;
    }

    /**
     * The screen with the keys in $scope put back: a decision to the character's value, a
     * colour by hand to the palette, a look choice to ''. Null when the scope names nothing.
     *
     * @param array<string, string> $decisions
     * @param array<string, string> $look
     * @return array{decisions: array<string, string>, look: array<string, string>}|null
     */
    public static function reset(array $decisions, array $look, string $scope, string $character): ?array
    {
        $keys = self::keys($scope);
        if ($keys === null) {
            return null;
        }
        $defaults = self::defaults($character);
        foreach ($keys as $key) {
            if (self::kind($key) === 'look') {
                $look[$key] = '';
            } else {
                $decisions[$key] = $defaults[$key];
            }
        }

        return ['decisions' => $decisions, 'look' => $look];
    }

    /**
     * A look as stored: every choice equal to the character's is '' (D-159). The form posts
     * the character's answer for a choice nobody touched — there is no "follow" button any
     * more, the character's answer is simply the one pressed — and storing it would pin it,
     * so the next character would not re-dress that part of the header.
     *
     * @param array<string, string> $look
     * @return array<string, string>
     */
    public static function settle(array $look, string $character): array
    {
        $characterLook = Characters::look($character);
        foreach ($look as $choice => $value) {
            if ($value !== '' && $value === ($characterLook[$choice] ?? null)) {
                $look[$choice] = '';
            }
        }

        return $look;
    }

    /**
     * How many of the owner's changes loading another character throws away: the decisions
     * and the colours by hand, which a character replaces whole. The look survives, being ''
     * wherever it follows (D-158).
     *
     * @param array<string, string> $decisions
     */
    public static function lost(array $decisions, string $character): int
    {
        return count(array_filter(
            self::changed($decisions, [], $character),
            static fn (string $key): bool => self::kind($key) !== 'look',
        ));
    }

    /**
     * How many of the owner's PUBLISHED changes a publish replaces (D-161): the decisions and
     * colours by hand the site holds over the character it was composed with, that what is
     * about to be published sets differently. Publish's question after a character is loaded
     * says so — the load's own question counted the screen, which can already have been
     * answered, or never asked when the screen held nothing of the owner's.
     *
     * @param array<string, string> $published the site's decisions
     * @param array<string, string> $trying the decisions about to be published
     */
    public static function replaced(array $published, array $trying, string $character): int
    {
        return count(array_filter(
            self::changed($published, [], $character),
            static fn (string $key): bool => self::kind($key) !== 'look' && !self::same($trying[$key] ?? '', $published[$key] ?? ''),
        ));
    }

    /** The section a key is in, or null. */
    public static function sectionOf(string $key): ?string
    {
        foreach (self::SECTIONS as $section => $groups) {
            foreach ($groups as $keys) {
                if (in_array($key, $keys, true)) {
                    return $section;
                }
            }
        }

        return null;
    }

    /**
     * Two stored values that mean the same: equal text, or the same number written two ways
     * ("72" and "72.0"), which a character's file and a slider can disagree about.
     */
    private static function same(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return is_numeric($a) && is_numeric($b) && abs((float) $a - (float) $b) < 1e-9;
    }
}
