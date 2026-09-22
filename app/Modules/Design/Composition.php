<?php

namespace App\Modules\Design;

use App\Core\Blocks;
use App\Core\Db;
use App\Core\Settings;

/**
 * Layer 0 reaching layers 2 and 3: the composition a character gives a site.
 *
 * The character a site last applied is remembered in settings, because new blocks have
 * to start from it. That is all it is used for: the saved design is still only the
 * layer-1 decisions in design_tokens, and existing blocks keep the section styles their
 * author chose until the author asks for them to be reset (SPEC §5.4).
 */
final class Composition
{
    private const SETTING = 'design_character';

    /**
     * The character new blocks start from. A site that never chose one composes like the
     * default character, whose tokens it is already rendering with.
     */
    public static function active(Db $db): string
    {
        $name = Settings::get($db, self::SETTING);

        return is_string($name) && Presets::exists($name) ? $name : Presets::DEFAULT;
    }

    public static function remember(Db $db, string $character): void
    {
        if (!Presets::exists($character)) {
            return;
        }
        Settings::set($db, self::SETTING, $character);
    }

    /**
     * The section style a block of this type starts from under this character.
     *
     * @return array<string, string|int|null>
     */
    public static function style(?string $character, string $blockType): array
    {
        if ($character === null || !isset(Presets::COMPOSITION[$character])) {
            return SectionStyle::DEFAULTS;
        }
        $composition = Presets::COMPOSITION[$character];
        $style = $composition['section'];
        $surface = $composition['surfaces'][$blockType] ?? null;
        if (is_string($surface)) {
            $style['surface'] = $surface;
        }
        // A divider is an accent on the transitions a character chooses, not a default
        // for every boundary, so only the named block types carry one.
        $divider = $composition['dividers'][$blockType] ?? null;
        if (is_string($divider)) {
            $style['divider'] = $divider;
        }

        return SectionStyle::normalize($style);
    }

    /**
     * The layout a block of this type starts from, always one the block declares.
     */
    public static function layout(Blocks $registry, ?string $character, string $blockType): string
    {
        $layout = $character === null ? null : (Presets::COMPOSITION[$character]['layouts'][$blockType] ?? null);

        return $registry->layout($blockType, $layout);
    }

    /**
     * Rewrites the section style and layout of every block on the site to this
     * character's composition. Destructive: it discards per-section choices, so it only
     * ever runs when the user picked it explicitly over saving the design alone.
     *
     * @return int the number of blocks changed
     */
    public static function apply(Db $db, Blocks $registry, string $character): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $changed = 0;

        /*
         * SECTION BY SECTION, COMPOSED FROM THE BLOCK IT HOLDS (D-095).
         *
         * This used to be one UPDATE per block type, site-wide. The style now lives on the
         * section, and a character composes layer 2 from a BLOCK TYPE — `surfaces[$type]`,
         * `dividers[$type]` — so the two only meet through the block a section holds. While
         * a section holds one block that is exact. **When a section can hold several, what
         * "Editorial gives a hero a tinted surface" means for a section holding a hero and a
         * form is an open question (PLAN.md D-093), and this is the code that will have to
         * answer it.**
         *
         * A section holding a block this installation cannot draw is left alone, which is
         * the rule the per-type loop followed for the same reason: its style is the only
         * record of what it was.
         */
        $rows = $db->all(
            'SELECT s.id, b.block_type FROM page_sections s
             LEFT JOIN page_blocks b ON b.section_id = s.id
             ORDER BY s.id',
        );
        foreach ($rows as $row) {
            $type = (string) ($row['block_type'] ?? '');
            if (!$registry->has($type)) {
                continue;
            }
            $changed += 1;
            $db->query(
                'UPDATE page_sections SET style_json = ?, updated_at = ? WHERE id = ?',
                [json_encode(self::style($character, $type), JSON_THROW_ON_ERROR), $now, (int) $row['id']],
            );
            // The layout is the block's own layer 3 and stays on the block row.
            $db->query(
                'UPDATE page_blocks SET layout = ?, updated_at = ? WHERE section_id = ? AND block_type = ?',
                [self::layout($registry, $character, $type), $now, (int) $row['id'], $type],
            );
        }

        return $changed;
    }

    /**
     * Whether the site has any block at all: what decides if applying a character has
     * anything to overwrite, and so whether the choice has to be offered.
     */
    public static function hasBlocks(Db $db): bool
    {
        return (int) ($db->one('SELECT COUNT(*) AS n FROM page_blocks')['n'] ?? 0) > 0;
    }
}
