<?php

namespace App\Modules\Pages;

use App\Core\Blocks;
use App\Core\Db;
use Closure;
use Throwable;

/**
 * Pages and their blocks. All SQL is portable between MySQL and SQLite (SPEC §5.0).
 */
final class Page
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(Db $db): array
    {
        return $db->all('SELECT id, locale, slug, title, status, updated_at FROM pages ORDER BY locale, slug');
    }

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
     * @return list<array{id: int, type: string, content: array<mixed>, style: array<mixed>}>
     */
    public static function blocks(Db $db, int $pageId): array
    {
        $blocks = [];
        $rows = $db->all(
            'SELECT id, block_type, content_json, style_json FROM page_blocks WHERE page_id = ? ORDER BY sort, id',
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
            ];
        }

        return $blocks;
    }

    /**
     * Creates a draft page with empty blocks of the given types. The page and each block
     * start their own content and block groups.
     *
     * @param list<string> $blockTypes
     */
    public static function create(Db $db, Blocks $registry, string $locale, string $title, string $slug, ?int $templateId, array $blockTypes): int
    {
        return self::transaction($db, static function () use ($db, $registry, $locale, $title, $slug, $templateId, $blockTypes): int {
            $now = gmdate('Y-m-d H:i:s');
            $db->query(
                'INSERT INTO pages (locale, slug, title, status, template_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$locale, $slug, $title, 'draft', $templateId, $now, $now],
            );
            $id = (int) $db->lastInsertId();
            $db->query('UPDATE pages SET content_group_id = id WHERE id = ?', [$id]);
            foreach ($blockTypes as $sort => $type) {
                self::insertBlock($db, $id, $type, $registry->normalize($type, []), $sort, $now);
            }

            return $id;
        });
    }

    /**
     * Saves the whole page: title, slug and the block list in order. Blocks with an id of
     * this page are updated (never their type), blocks without one are inserted, and this
     * page's blocks missing from the list are deleted. A null content keeps the stored
     * content, for blocks whose type is no longer installed.
     *
     * @param list<array{id: int|null, type: string, content: array<string, mixed>|null}> $blocks
     */
    public static function update(Db $db, int $id, string $title, string $slug, array $blocks): void
    {
        self::transaction($db, static function () use ($db, $id, $title, $slug, $blocks): void {
            $now = gmdate('Y-m-d H:i:s');
            $db->query('UPDATE pages SET title = ?, slug = ?, updated_at = ? WHERE id = ?', [$title, $slug, $now, $id]);

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
                            'UPDATE page_blocks SET sort = ?, content_json = ?, updated_at = ? WHERE id = ? AND page_id = ?',
                            [$sort, self::json($block['content']), $now, $block['id'], $id],
                        );
                    }
                } elseif ($block['content'] !== null) {
                    self::insertBlock($db, $id, $block['type'], $block['content'], $sort, $now);
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
     * @param array<string, mixed> $content
     */
    private static function insertBlock(Db $db, int $pageId, string $type, array $content, int $sort, string $now): void
    {
        $db->query(
            'INSERT INTO page_blocks (page_id, block_type, sort, content_json, style_json, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$pageId, $type, $sort, self::json($content), '{}', $now, $now],
        );
        $blockId = (int) $db->lastInsertId();
        $db->query('UPDATE page_blocks SET block_group_id = id WHERE id = ?', [$blockId]);
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
