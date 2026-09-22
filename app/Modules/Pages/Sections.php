<?php

namespace App\Modules\Pages;

use App\Core\Db;
use App\Modules\Design\SectionStyle;

/**
 * The section a block sits in, and the layer-2 style it owns (PLAN.md D-093, migration 0026).
 *
 * A page is a list of sections; a section holds blocks in its columns; depth is exactly two.
 * **In this step every section holds exactly one block**, so nothing on any screen changes —
 * what changed is where the style lives. Columns come next, and this is the file they will
 * grow in.
 *
 * WHY THE STYLE BELONGS HERE. surface, rhythm, width, align, divider and the background
 * picture have always been section language applied per block, because there was nothing
 * else to hang them on. A tinted band of three text blocks meant setting one style three
 * times and hoping they stayed in step.
 *
 * THE BLOCK CONTRACT IS UNTOUCHED, which is what makes the whole change affordable: a block
 * is still a leaf with fields, and every definition, template, validator and repeater is
 * unaffected. Nothing in this file knows what a block type is.
 */
final class Sections
{
    /** The only layout a section has until columns arrive. */
    public const ONE = 'one';

    /**
     * Every section of a page: id => ['sort' => int, 'layout' => string, 'style' => array].
     *
     * Read in one statement rather than per block, for the reason MediaPicture::forBlocks()
     * gives about pictures: a page draws in a fixed number of queries or it does not scale.
     *
     * @return array<int, array{sort: int, layout: string, style: array<string, string|int|null>}>
     */
    public static function forPage(Db $db, int $pageId): array
    {
        $sections = [];
        foreach ($db->all(
            'SELECT id, sort, layout, style_json FROM page_sections WHERE page_id = ? ORDER BY sort, id',
            [$pageId],
        ) as $row) {
            $style = json_decode((string) $row['style_json'], true);
            $sections[(int) $row['id']] = [
                'sort' => (int) $row['sort'],
                'layout' => (string) $row['layout'],
                'style' => SectionStyle::normalize(is_array($style) ? $style : []),
            ];
        }

        return $sections;
    }

    /**
     * Write a section's style and place, creating it when the block has none yet.
     *
     * The style is resolved against `media` here rather than by the caller, so a background
     * picture that has been deleted since becomes null on save wherever the save came from —
     * the rule D-024 set, kept in one place now that there is one place for it.
     *
     * @param array<string, string|int|null> $style
     * @return int the section's id, for the block row to point at
     */
    public static function save(Db $db, int $pageId, ?int $sectionId, int $sort, array $style, string $now): int
    {
        $json = self::json(SectionStyle::resolve($db, SectionStyle::normalize($style)));

        // A section id from a form is somebody's input until it is shown to belong to this
        // page; a stale one makes a new section rather than writing over a stranger's.
        $existing = $sectionId === null ? null : $db->one(
            'SELECT id FROM page_sections WHERE id = ? AND page_id = ?',
            [$sectionId, $pageId],
        );
        if ($existing !== null) {
            $db->query(
                'UPDATE page_sections SET sort = ?, style_json = ?, updated_at = ? WHERE id = ? AND page_id = ?',
                [$sort, $json, $now, $sectionId, $pageId],
            );

            return (int) $sectionId;
        }

        $db->query(
            'INSERT INTO page_sections (page_id, sort, layout, style_json, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$pageId, $sort, self::ONE, $json, $now, $now],
        );

        return (int) $db->lastInsertId();
    }

    /**
     * Delete this page's sections that no longer hold a block.
     *
     * Blocks are removed by Page::update() one at a time, and a section left holding nothing
     * would go on drawing its surface and its rhythm around an empty container. Done as a
     * read and then deletes by id rather than as a DELETE with a subquery over a joined
     * table, which MySQL refuses where SQLite allows it (SPEC §5.0 portability).
     */
    public static function prune(Db $db, int $pageId): void
    {
        $empty = [];
        foreach ($db->all('SELECT id FROM page_sections WHERE page_id = ?', [$pageId]) as $row) {
            $id = (int) $row['id'];
            $holds = $db->one('SELECT COUNT(*) AS n FROM page_blocks WHERE section_id = ?', [$id]);
            if ((int) ($holds['n'] ?? 0) === 0) {
                $empty[] = $id;
            }
        }
        foreach ($empty as $id) {
            $db->query('DELETE FROM page_sections WHERE id = ?', [$id]);
        }
    }

    /**
     * Copy a page's sections onto another page, returning old id => new id.
     *
     * For a translation (Translations::create), which copies a page's blocks verbatim into
     * another locale: without the sections coming too, a Croatian page would be drawn with
     * whatever arrangement its own blocks happened to have.
     *
     * @return array<int, int>
     */
    public static function copy(Db $db, int $fromPageId, int $toPageId, string $now): array
    {
        $map = [];
        foreach ($db->all(
            'SELECT id, sort, layout, style_json FROM page_sections WHERE page_id = ? ORDER BY sort, id',
            [$fromPageId],
        ) as $row) {
            $db->query(
                'INSERT INTO page_sections (page_id, sort, layout, style_json, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$toPageId, (int) $row['sort'], (string) $row['layout'], (string) $row['style_json'], $now, $now],
            );
            $map[(int) $row['id']] = (int) $db->lastInsertId();
        }

        return $map;
    }

    /**
     * @param array<string, string|int|null> $style
     */
    private static function json(array $style): string
    {
        return $style === []
            ? '{}'
            : (string) json_encode($style, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
