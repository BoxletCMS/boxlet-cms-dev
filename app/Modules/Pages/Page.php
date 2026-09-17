<?php

namespace App\Modules\Pages;

use App\Core\Blocks;
use App\Core\Db;
use App\Modules\Design\Composition;
use App\Modules\Design\SectionStyle;
use Closure;
use Throwable;

/**
 * Pages and their blocks. All SQL is portable between MySQL and SQLite (SPEC §5.0).
 *
 * @phpstan-type BlockRow array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string}
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
     * @return list<array{id: int|null, type: string, content: array<string, mixed>|null, style: array<string, string|int|null>, layout: string}>
     */
    public static function editable(Db $db, Blocks $registry, int $pageId): array
    {
        $blocks = [];
        foreach (self::blocks($db, $pageId) as $block) {
            $known = $registry->has($block['type']);
            $blocks[] = [
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
        return self::transaction($db, static function () use ($db, $registry, $locale, $title, $slug, $templateId, $blockTypes, $character): int {
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
                    'id' => null,
                    'type' => $type,
                    'content' => $registry->normalize($type, []),
                    'style' => Composition::style($character, $type),
                    'layout' => Composition::layout($registry, $character, $type),
                ];
                self::insertBlock($db, $id, $block, $sort, $now);
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
     * @param array{title: string, slug: string, parent_id: int|null, status: string} $page
     * @param list<BlockRow> $blocks
     */
    public static function update(Db $db, int $id, array $page, array $blocks): void
    {
        self::transaction($db, static function () use ($db, $id, $page, $blocks): void {
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
                'UPDATE pages SET title = ?, slug = ?, parent_id = ?, sort = ?, status = ?,
                        published_at = COALESCE(published_at, ?), updated_at = ?
                 WHERE id = ?',
                [
                    $page['title'],
                    $page['slug'],
                    $page['parent_id'],
                    $sort,
                    $page['status'],
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
                            [$sort, self::json($block['content']), self::json(SectionStyle::resolve($db, $block['style'])), $block['layout'], $now, $block['id'], $id],
                        );
                    }
                } elseif ($block['content'] !== null) {
                    self::insertBlock($db, $id, $block, $sort, $now);
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
    private static function insertBlock(Db $db, int $pageId, array $block, int $sort, string $now): void
    {
        // A section's picture is validated here rather than in normalize(), which is pure
        // and called from places with no database (D-024). An id naming a picture that has
        // since been deleted becomes null, and the section renders as it did before
        // pictures existed.
        $block['style'] = SectionStyle::resolve($db, $block['style']);
        $db->query(
            'INSERT INTO page_blocks (page_id, block_type, sort, content_json, style_json, layout, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$pageId, $block['type'], $sort, self::json($block['content'] ?? []), self::json($block['style']), $block['layout'], $now, $now],
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

    /**
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    private static function transaction(Db $db, Closure $work): mixed
    {
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
