<?php

namespace App\Modules\Settings;

use App\Core\Db;
use App\Modules\Design\Composition;

/**
 * How the header and footer look (PLAN.md D-032, D-036): a closed set of choices, each
 * with a default the character supplies and an override the owner may set.
 *
 * NO FREE VALUE ANYWHERE. A surface is one of the three the sections already use, so the
 * palette's contrast guarantee holds for the chrome exactly as it does for a block; the
 * rest are sizes and arrangements the stylesheet has a rule for, nothing more.
 *
 * STORED AS "FOLLOW THE CHARACTER" UNLESS CHOSEN. An empty setting means the character
 * decides, so changing character re-dresses the chrome the way it re-composes a page,
 * while a choice the owner made stays theirs. The same split the Design screen draws
 * between a character's defaults and the decisions saved over them.
 */
final class ChromeLook
{
    /** Every choice and its values; the first value is never assumed to be a default. */
    public const OPTIONS = [
        'header_layout' => ['left', 'centred', 'transparent', 'sticky'],
        'footer_layout' => ['simple', 'columns'],
        'header_surface' => ['plain', 'tinted', 'contrast'],
        'footer_surface' => ['plain', 'tinted', 'contrast'],
        'density' => ['compact', 'normal', 'roomy'],
        'header_rule' => ['on', 'off'],
        'logo_size' => ['small', 'medium', 'large'],
        /* How many columns the footer's MENU runs in, and only when the footer is in
         * columns at all (PLAN.md D-067). The handoff asks for the footer's own grid to take
         * this number; measured against what a footer actually holds — the owner's words,
         * the menu, the switcher and the small print — three and four columns would leave
         * two of them empty. A long menu is the thing that really needs the room. */
        'footer_columns' => ['2', '3', '4'],
    ];

    /**
     * What each character gives its chrome. Kept beside the choices rather than in
     * Presets, which holds what a character does to the page: the header and footer are
     * their own screen and their own decision (D-028), and this is the only reader.
     */
    public const CHARACTER = [
        // A masthead: the name on the left, a hairline under it, air around both.
        'editorial' => ['header_layout' => 'left', 'footer_layout' => 'simple', 'header_surface' => 'plain', 'footer_surface' => 'tinted', 'density' => 'roomy', 'header_rule' => 'on', 'logo_size' => 'medium', 'footer_columns' => '2'],
        // Everything on one axis and as little of it as possible.
        'minimal' => ['header_layout' => 'centred', 'footer_layout' => 'simple', 'header_surface' => 'plain', 'footer_surface' => 'plain', 'density' => 'normal', 'header_rule' => 'off', 'logo_size' => 'small', 'footer_columns' => '2'],
        // The header over the first section, which is where Bold spends its colour.
        'bold' => ['header_layout' => 'transparent', 'footer_layout' => 'columns', 'header_surface' => 'plain', 'footer_surface' => 'contrast', 'density' => 'normal', 'header_rule' => 'off', 'logo_size' => 'large', 'footer_columns' => '3'],
        // Always within reach, on a soft tint, with room to breathe.
        'soft' => ['header_layout' => 'sticky', 'footer_layout' => 'columns', 'header_surface' => 'tinted', 'footer_surface' => 'tinted', 'density' => 'roomy', 'header_rule' => 'off', 'logo_size' => 'medium', 'footer_columns' => '2'],
        // A slab of contrast, packed tight, ruled off.
        'brutalist' => ['header_layout' => 'left', 'footer_layout' => 'columns', 'header_surface' => 'contrast', 'footer_surface' => 'contrast', 'density' => 'compact', 'header_rule' => 'on', 'logo_size' => 'large', 'footer_columns' => '3'],
    ];

    /**
     * What the owner chose, '' for every choice left to the character.
     *
     * @return array<string, string>
     */
    public static function stored(Db $db): array
    {
        $values = SiteChrome::look($db, array_keys(self::OPTIONS));

        $stored = [];
        foreach (self::OPTIONS as $name => $options) {
            $value = $values[$name] ?? '';
            $stored[$name] = is_string($value) && in_array($value, $options, true) ? $value : '';
        }

        return $stored;
    }

    /**
     * The look to draw, in three levels: what is being TRIED, then what the owner SAVED,
     * then what the CHARACTER gives.
     *
     * $overrides is what an admin preview is showing without having saved it, and it holds
     * only the choices that request actually named — see fromRequest(). $character is the
     * one being previewed, so a choice left to the character follows the character on the
     * screen rather than the one the site is published with.
     *
     * @param array<string, string> $overrides choice => value, '' meaning follow the character
     * @return array<string, string>
     */
    public static function resolve(Db $db, array $overrides = [], string $character = ''): array
    {
        $defaults = self::CHARACTER[$character !== '' ? $character : Composition::active($db)] ?? self::CHARACTER['minimal'];
        $look = [];
        foreach (self::stored($db) as $name => $stored) {
            $value = $overrides[$name] ?? $stored;
            $look[$name] = $value !== '' ? $value : $defaults[$name];
        }

        return $look;
    }

    /** The form field that carries one choice. A field name is not a settings key. */
    public static function field(string $choice): string
    {
        return 'look_' . $choice;
    }

    /**
     * The look choices a request is trying, for a preview. Each is a value from its own
     * closed set, or '' for "follow the character"; anything else falls back to ''.
     *
     * ONLY THE CHOICES THE REQUEST NAMED. A request that is silent about a choice means the
     * owner's saved one, not a reset — and the difference matters: the design preview sends
     * no look at all, so returning all seven as '' would make it draw chrome the site does
     * not have.
     *
     * READS A REQUEST AND WRITES NOTHING. It takes an array and returns an array; it has no
     * database to write to. Saving stays SiteChrome::saveLook(), reached only through a POST
     * with a CSRF token.
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public static function fromRequest(array $input): array
    {
        $look = [];
        foreach (self::OPTIONS as $name => $options) {
            if (!array_key_exists(self::field($name), $input)) {
                continue;
            }
            $value = $input[self::field($name)];
            $look[$name] = is_string($value) && in_array($value, $options, true) ? $value : '';
        }

        return $look;
    }

    /**
     * Writes the owner's choices. Anything outside a closed set is stored as '' — follow
     * the character — rather than refused: every value here comes from a select the form
     * drew, so an unknown one is a stale form or a hand-made request, not a typing error
     * worth a message.
     *
     * @param array<string, string> $values
     */
    public static function save(Db $db, array $values): void
    {
        $look = [];
        foreach (self::OPTIONS as $name => $options) {
            $value = $values[$name] ?? '';
            $look[$name] = in_array($value, $options, true) ? $value : '';
        }
        SiteChrome::saveLook($db, $look);
    }
}
