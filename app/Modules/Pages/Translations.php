<?php

namespace App\Modules\Pages;

use App\Core\Blocks;
use App\Core\Db;

/**
 * A page in another language (SPEC §5.2, PLAN.md D-043): the row-based model, where a
 * translation is a page of its own tied to its source by content_group_id, and each of its
 * blocks to the source block by block_group_id.
 *
 * A NEW TRANSLATION IS A COPY, a draft, with the source language's words in it for the
 * owner to replace. Everything else a translation needs is already right in the copy: the
 * pictures, the section styles and layouts, and links to other pages, which are references
 * followed into the translation's own language at render (D-034).
 *
 * source_hash records what each block was translated FROM: a hash of the source block's
 * translatable fields at the moment of copying. When the source's words change, the hash
 * no longer matches and that block alone is marked stale (step 3). Only the translatable
 * fields count, because only they are the translator's work; a picture swapped in the
 * source is not something a translation has fallen behind on.
 */
final class Translations
{
    /**
     * Every language's version of a page's group, keyed by locale, each with its id.
     *
     * @return array<string, int>
     */
    public static function of(Db $db, int $pageId): array
    {
        $page = $db->one('SELECT id, content_group_id FROM pages WHERE id = ?', [$pageId]);
        if ($page === null) {
            return [];
        }
        $group = (int) ($page['content_group_id'] ?? $page['id']);
        $versions = [];
        foreach ($db->all('SELECT id, locale FROM pages WHERE content_group_id = ? OR id = ?', [$group, $group]) as $row) {
            $versions[(string) $row['locale']] = (int) $row['id'];
        }

        return $versions;
    }

    /**
     * Creates this page's version in $locale as a draft copy, or finds the one that exists.
     *
     * @return int|string the translation's page id, or a message saying why there is none
     */
    public static function create(Db $db, Blocks $registry, int $pageId, string $locale): int|string
    {
        $page = Page::find($db, $pageId);
        if ($page === null) {
            return t('pages.not_found');
        }
        if ($db->one('SELECT code FROM locales WHERE code = ? AND enabled = 1', [$locale]) === null) {
            return t('translations.unknown_language');
        }
        $existing = self::of($db, $pageId);
        if (isset($existing[$locale])) {
            return $existing[$locale];
        }

        // Translated from the group's source, whichever version the owner asked from: a
        // translation of a translation would inherit the first one's mistakes, and its
        // hashes would point at text that is not the source.
        $group = (int) ($page['content_group_id'] ?? $page['id']);
        $source = $db->one("SELECT * FROM pages WHERE content_group_id = ? AND translation_status = 'source'", [$group]) ?? $page;
        $sourceId = (int) $source['id'];

        return $db->transaction(static function () use ($db, $registry, $source, $sourceId, $group, $locale): int {
            $now = gmdate('Y-m-d H:i:s');
            $slug = (string) $source['slug'];
            if (Slug::problem($db, $locale, $slug, null) !== null) {
                $slug = Slug::unique($db, $locale, (string) $source['title'], null);
            }
            $parent = self::parentIn($db, $source['parent_id'] === null ? null : (int) $source['parent_id'], $locale);

            $db->query(
                "INSERT INTO pages (content_group_id, locale, slug, title, status, template_id, parent_id, sort, seo_json,
                                    translation_status, source_hash, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?, 'reviewed', ?, ?, ?)",
                [
                    $group,
                    $locale,
                    $slug,
                    (string) $source['title'],
                    $source['template_id'],
                    $parent,
                    PageTree::nextSort($db, $locale, $parent),
                    $source['seo_json'],
                    self::pageHash($source),
                    $now,
                    $now,
                ],
            );
            $id = (int) $db->lastInsertId();

            foreach ($db->all('SELECT * FROM page_blocks WHERE page_id = ? ORDER BY sort, id', [$sourceId]) as $block) {
                $type = (string) $block['block_type'];
                $content = json_decode((string) $block['content_json'], true);
                $hash = $registry->has($type) && is_array($content) ? self::blockHash($registry, $type, $content) : null;
                $db->query(
                    "INSERT INTO page_blocks (page_id, block_group_id, block_type, sort, content_json, style_json, layout,
                                              translation_status, source_hash, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'reviewed', ?, ?, ?)",
                    [
                        $id,
                        (int) ($block['block_group_id'] ?? $block['id']),
                        $type,
                        (int) $block['sort'],
                        (string) $block['content_json'],
                        (string) $block['style_json'],
                        (string) ($block['layout'] ?? ''),
                        $hash,
                        $now,
                        $now,
                    ],
                );
            }

            return $id;
        });
    }

    /**
     * A hash of what a translator works from in one block: its translatable fields, and
     * inside a repeater each item's translatable fields, in order.
     *
     * @param array<string, mixed> $content
     */
    public static function blockHash(Blocks $registry, string $type, array $content): string
    {
        return hash('sha256', json_encode(
            self::translatable($registry->get($type)['fields'], $content),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * A hash of a page's own words: its title and what it tells search engines.
     *
     * @param array<string, mixed> $page
     */
    public static function pageHash(array $page): string
    {
        $seo = Page::seo($page);

        return hash('sha256', json_encode([(string) $page['title'], $seo], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private static function translatable(array $fields, array $content): array
    {
        $words = [];
        foreach ($fields as $name => $field) {
            if ($field['type'] === 'repeater') {
                $items = [];
                foreach (is_array($content[$name] ?? null) ? $content[$name] : [] as $item) {
                    $items[] = self::translatable($field['fields'], is_array($item) ? $item : []);
                }
                $words[$name] = $items;
            } elseif (($field['translatable'] ?? false) === true) {
                $words[$name] = $content[$name] ?? null;
            }
        }

        return $words;
    }

    /**
     * The source's parent, in the translation's language: the page of the same group
     * there, or no parent when the parent has not been translated yet.
     */
    private static function parentIn(Db $db, ?int $parentId, string $locale): ?int
    {
        if ($parentId === null) {
            return null;
        }
        $parent = $db->one('SELECT id, content_group_id FROM pages WHERE id = ?', [$parentId]);
        if ($parent === null) {
            return null;
        }
        $group = (int) ($parent['content_group_id'] ?? $parent['id']);
        $there = $db->one('SELECT id FROM pages WHERE content_group_id = ? AND locale = ?', [$group, $locale]);

        return $there === null ? null : (int) $there['id'];
    }
}
