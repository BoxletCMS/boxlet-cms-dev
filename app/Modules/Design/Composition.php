<?php

namespace App\Modules\Design;

use App\Core\Blocks;
use App\Core\Db;

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
        $row = $db->one('SELECT value_json FROM settings WHERE `key` = ?', [self::SETTING]);
        $name = $row === null ? null : json_decode((string) $row['value_json'], true);

        return is_string($name) && Presets::exists($name) ? $name : Presets::DEFAULT;
    }

    public static function remember(Db $db, string $character): void
    {
        if (!Presets::exists($character)) {
            return;
        }
        $db->query('DELETE FROM settings WHERE `key` = ?', [self::SETTING]);
        $db->query(
            'INSERT INTO settings (`key`, value_json) VALUES (?, ?)',
            [self::SETTING, json_encode($character, JSON_THROW_ON_ERROR)],
        );
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
        foreach ($db->all('SELECT DISTINCT block_type FROM page_blocks') as $row) {
            $type = (string) $row['block_type'];
            if (!$registry->has($type)) {
                continue; // a block this installation no longer has: leave it untouched
            }
            $changed += (int) ($db->one('SELECT COUNT(*) AS n FROM page_blocks WHERE block_type = ?', [$type])['n'] ?? 0);
            $db->query(
                'UPDATE page_blocks SET style_json = ?, layout = ?, updated_at = ? WHERE block_type = ?',
                [
                    json_encode(self::style($character, $type), JSON_THROW_ON_ERROR),
                    self::layout($registry, $character, $type),
                    $now,
                    $type,
                ],
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
