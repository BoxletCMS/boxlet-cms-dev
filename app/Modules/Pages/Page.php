<?php

namespace App\Modules\Pages;

use App\Core\Blocks;
use App\Core\Db;
use App\Modules\Design\Composition;
use App\Modules\Design\SectionStyle;
use App\Modules\Media\MediaReference;

/**
 * Pages and their blocks. All SQL is portable between MySQL and SQLite (SPEC §5.0).
 *
 * @phpstan-type BlockRow array{key: string, id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string}
 */
final class Page
{
    /**
     * @return array<string, mixed>|null
     */
    public static function find(Db $db, int $id): ?array
    {
        return $db->one('SELECT * FROM pages WHERE id = ?', [$id]);
    }

    /**
     * The page visitors see at $slug in $locale, or null when missing or unpublished.
     *
     * @return array<string, mixed>|null
     */
    public static function published(Db $db, string $locale, string $slug): ?array
    {
        return $db->one(
            'SELECT * FROM pages WHERE locale = ? AND slug = ? AND status = ?',
            [$locale, $slug, 'published'],
        );
    }

    /**
     * What this page says about itself in <head>: a title and a description (D-004).
     * Two fields, and nothing else — no sharing image, no robots directive, no sitemap.
     *
     * WHAT IS STORED, NOT WHAT IS SHOWN. Neither field falls back here, deliberately.
     * The title's fallback to the page title belongs where it is rendered, because a
     * fallback applied here would be written straight back on the next save: the page
     * title would become an explicit SEO title and would stop following the title from
     * then on. The editors want the raw value too, or an owner cannot tell a field they
     * set from one they inherited.
     *
     * A page created before this existed — and every new page, since create() does not
     * write the column — has NULL rather than '{}', so both have to decode to nothing.
     *
     * @param array<string, mixed> $page a row from find() or published()
     * @return array{title: string, description: string}
     */
    public static function seo(array $page): array
    {
        $stored = json_decode((string) ($page['seo_json'] ?? ''), true);
        $stored = is_array($stored) ? $stored : [];

        return [
            'title' => is_string($stored['title'] ?? null) ? trim($stored['title']) : '',
            'description' => is_string($stored['description'] ?? null) ? trim($stored['description']) : '',
        ];
    }

    /**
     * The inverse of seo(), kept beside it: both halves of one stored shape belong
     * together, and the alternative is json_encode's flags copied into a controller,
     * where they drift.
     *
     * An empty field is dropped rather than stored as an empty string, so a page with no
     * SEO of its own holds {} however it arrived there.
     *
     * @param array{title: string, description: string} $seo
     */
    public static function seoJson(array $seo): string
    {
        return self::json(array_filter(
            ['title' => trim($seo['title']), 'description' => trim($seo['description'])],
            static fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * Stored blocks in order, exactly as stored: callers validate layout and style
     * against the registry when they use them.
     *
     * @return list<array{id: int, type: string, content: array<mixed>, style: array<mixed>, layout: string}>
     */
    public static function blocks(Db $db, int $pageId): array
    {
        $blocks = [];
        $rows = $db->all(
            'SELECT id, block_type, content_json, style_json, layout FROM page_blocks WHERE page_id = ? ORDER BY sort, id',
            [$pageId],
        );
        foreach ($rows as $row) {
            $content = json_decode((string) $row['content_json'], true);
            $style = json_decode((string) $row['style_json'], true);
            $blocks[] = [
                'id' => (int) $row['id'],
                'type' => (string) $row['block_type'],
                'content' => is_array($content) ? $content : [],
                'style' => is_array($style) ? $style : [],
                'layout' => (string) $row['layout'],
            ];
        }

        return $blocks;
    }

    /**
     * This page's blocks as an editor needs them: content and layout normalised against
     * the registry, style normalised against the closed sets, and a block whose type is
     * no longer installed kept with a null content so saving cannot discard it.
     *
     * Both editors render from this, so the visual canvas and the fallback form always
     * agree about what is on the page.
     *
     * @return list<array{key: string, id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string}>
     */
    public static function editable(Db $db, Blocks $registry, int $pageId): array
    {
        $blocks = [];
        foreach (self::blocks($db, $pageId) as $block) {
            $known = $registry->has($block['type']);
            $blocks[] = [
                // A stored block's key is its id (D-094); nothing about it has to be
                // remembered between renders.
                'key' => BlockForm::key($block['id'], 0),
                'id' => $block['id'],
                'type' => $block['type'],
                'content' => $known ? $registry->normalize($block['type'], $block['content']) : null,
                'style' => SectionStyle::normalize($block['style']),
                'layout' => $known ? $registry->layout($block['type'], $block['layout']) : '',
            ];
        }

        return $blocks;
    }

    /**
     * Creates a draft page with empty blocks of the given types, each composed as the
     * active character composes that block type (SPEC §5.4). The page and each block
     * start their own groups.
     *
     * @param list<string> $blockTypes
     * @param string|null  $character   the character new blocks start from; null for the
     *                                  block's own defaults
     */
    public static function create(Db $db, Blocks $registry, string $locale, string $title, string $slug, ?int $templateId, array $blockTypes, ?string $character = null): int
    {
        return $db->transaction(static function () use ($db, $registry, $locale, $title, $slug, $templateId, $blockTypes, $character): int {
            $now = gmdate('Y-m-d H:i:s');
            // A new page goes last among its siblings. Without this every page keeps the
            // column's default of 0, they all tie, and the order a drag just set is
            // decided by the title tie-break instead (PLAN.md D-011). New pages are
            // top-level: the parent is chosen afterwards, in page settings.
            $next = PageTree::nextSort($db, $locale, null);
            $db->query(
                'INSERT INTO pages (locale, slug, title, status, template_id, sort, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$locale, $slug, $title, 'draft', $templateId, $next, $now, $now],
            );
            $id = (int) $db->lastInsertId();
            $db->query('UPDATE pages SET content_group_id = id WHERE id = ?', [$id]);
            foreach ($blockTypes as $sort => $type) {
                $block = [
                    // Never rendered in an editor, so the key only has to exist and differ.
                    'key' => BlockForm::key(null, $sort),
                    'id' => null,
                    'type' => $type,
                    'content' => $registry->fresh($type),
                    'style' => Composition::style($character, $type),
                    'layout' => Composition::layout($registry, $character, $type),
                ];
                self::insertBlock($db, $registry, $id, $block, $sort, $now);
            }

            return $id;
        });
    }

    /**
     * Saves the whole page: its settings, and the block list in order. Blocks with an id
     * of this page are updated (never their type), blocks without one are inserted, and
     * this page's blocks missing from the list are deleted. A null content keeps
     * everything stored, for blocks whose type is no longer installed.
     *
     * The settings travel as one array rather than as a growing list of parameters,
     * because every caller has to pass all of them: a save that left one out would write
     * a default over whatever the page already had.
     *
     * The registry is here because saving resolves media references: which fields of a
     * block can hold a picture is something only the registry knows.
     *
     * seo_json travels already encoded, by Page::seoJson(), because it is also what
     * a rejected save hands back to the editor: one name for the column, the settings
     * array and the re-rendered form means those three can never drift apart.
     *
     * @param array{title: string, slug: string, parent_id: int|null, status: string, seo_json: string} $page
     * @param list<BlockRow> $blocks
     */
    public static function update(Db $db, Blocks $registry, int $id, array $page, array $blocks): void
    {
        $db->transaction(static function () use ($db, $registry, $id, $page, $blocks): void {
            $now = gmdate('Y-m-d H:i:s');
            // A page that changes parent joins a different set of siblings, where its old
            // position means nothing and collides with whoever already holds it. It goes
            // last in the group it arrives in, the same rule a new page follows.
            $current = $db->one('SELECT locale, parent_id, sort FROM pages WHERE id = ?', [$id]);
            $sort = (int) ($current['sort'] ?? 0);
            if ($current !== null && (int) ($current['parent_id'] ?? 0) !== (int) ($page['parent_id'] ?? 0)) {
                $sort = PageTree::nextSort($db, (string) $current['locale'], $page['parent_id']);
            }
            // published_at is stamped the first time a page is published and kept after
            // that, the same rule setStatus() follows.
            $db->query(
                'UPDATE pages SET title = ?, slug = ?, parent_id = ?, sort = ?, status = ?, seo_json = ?,
                        published_at = COALESCE(published_at, ?), updated_at = ?
                 WHERE id = ?',
                [
                    $page['title'],
                    $page['slug'],
                    $page['parent_id'],
                    $sort,
                    $page['status'],
                    $page['seo_json'],
                    $page['status'] === 'published' ? $now : null,
                    $now,
                    $id,
                ],
            );

            $existing = [];
            foreach ($db->all('SELECT id FROM page_blocks WHERE page_id = ?', [$id]) as $row) {
                $existing[(int) $row['id']] = true;
            }
            foreach ($blocks as $sort => $block) {
                if ($block['id'] !== null && isset($existing[$block['id']])) {
                    unset($existing[$block['id']]);
                    if ($block['content'] === null) {
                        $db->query('UPDATE page_blocks SET sort = ? WHERE id = ? AND page_id = ?', [$sort, $block['id'], $id]);
                    } else {
                        $db->query(
                            'UPDATE page_blocks SET sort = ?, content_json = ?, style_json = ?, layout = ?, updated_at = ? WHERE id = ? AND page_id = ?',
                            [
                                $sort,
                                self::json(MediaReference::resolve($db, $registry, $block['type'], $block['content'])),
                                self::json(SectionStyle::resolve($db, $block['style'])),
                                $block['layout'],
                                $now,
                                $block['id'],
                                $id,
                            ],
                        );
                    }
                } elseif ($block['content'] !== null) {
                    self::insertBlock($db, $registry, $id, $block, $sort, $now);
                }
            }
            foreach (array_keys($existing) as $blockId) {
                $db->query('DELETE FROM page_blocks WHERE id = ? AND page_id = ?', [$blockId, $id]);
            }
        });
    }

    public static function setStatus(Db $db, int $id, bool $published): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $db->query(
            'UPDATE pages SET status = ?, published_at = COALESCE(published_at, ?), updated_at = ? WHERE id = ?',
            [$published ? 'published' : 'draft', $published ? $now : null, $now, $id],
        );
    }

    /**
     * Deletes the page; its blocks go with it through the foreign key.
     */
    public static function delete(Db $db, int $id): void
    {
        $db->query('DELETE FROM pages WHERE id = ?', [$id]);
    }

    /**
     * @return list<array{id: int, name: string, builtin: bool, blocks: list<string>}>
     */
    public static function templates(Db $db): array
    {
        $templates = [];
        foreach ($db->all('SELECT id, name, layout_json, is_builtin FROM templates ORDER BY id') as $row) {
            $layout = json_decode((string) $row['layout_json'], true);
            $types = is_array($layout) && is_array($layout['blocks'] ?? null) ? $layout['blocks'] : [];
            $templates[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'builtin' => (int) $row['is_builtin'] === 1,
                'blocks' => array_values(array_filter($types, 'is_string')),
            ];
        }

        return $templates;
    }

    /**
     * @param BlockRow $block
     */
    private static function insertBlock(Db $db, Blocks $registry, int $pageId, array $block, int $sort, string $now): void
    {
        // Pictures are validated here rather than in normalize(), which is pure and called
        // from places with no database (D-024, extended to block content). An id naming a
        // picture that does not exist becomes null: a section renders as it did before
        // pictures existed, and a block shows its placeholder. Leaving a dangling id would
        // let the next picture to take that number be adopted by the page silently.
        $block['style'] = SectionStyle::resolve($db, $block['style']);
        $content = MediaReference::resolve($db, $registry, $block['type'], $block['content'] ?? []);
        $db->query(
            'INSERT INTO page_blocks (page_id, block_type, sort, content_json, style_json, layout, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$pageId, $block['type'], $sort, self::json($content), self::json($block['style']), $block['layout'], $now, $now],
        );
        $blockId = (int) $db->lastInsertId();
        $db->query('UPDATE page_blocks SET block_group_id = id WHERE id = ?', [$blockId]);
    }

    /**
     * Encodes content or style. An empty style is stored as an object, not a list.
     *
     * @param array<string, mixed> $value
     */
    private static function json(array $value): string
    {
        return $value === [] ? '{}' : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
