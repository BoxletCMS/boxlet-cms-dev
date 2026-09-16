<?php

namespace App\Modules\Pages;

use App\Core\Db;

/**
 * The page hierarchy. `parent_id` has been in the schema since Slice 3 with nothing
 * reading it; this is what reads it.
 *
 * It sits beside Page rather than inside it because Page is already close to the file
 * size limit, and because the same tree is what breadcrumbs, the page_list block and
 * nested addresses (PLAN.md §5, O-10) will each need.
 */
final class PageTree
{
    /**
     * The pages that may be the parent of $pageId, in tree order, each with the depth it
     * sits at so a select can indent them.
     *
     * A page may not be its own parent, nor be parented to one of its own descendants.
     * Either makes a cycle, and a cycle is not a display problem: every later reader of
     * the tree — breadcrumbs, menus, an address built from ancestors — would have to
     * defend against looping for ever.
     *
     * @param int|null $pageId the page being edited, or null when creating one
     * @return list<array{id: int, title: string, depth: int}>
     */
    public static function parentOptions(Db $db, string $locale, ?int $pageId): array
    {
        $children = self::children($db, $locale);
        $excluded = [];
        if ($pageId !== null) {
            $excluded = self::descendants($children, $pageId);
            $excluded[$pageId] = true;
        }

        $options = [];
        self::walk($children, 0, 0, $excluded, $options);

        return $options;
    }

    /**
     * Every page in $locale keyed by its parent, with a missing parent keyed under 0.
     *
     * @return array<int, list<array{id: int, title: string}>>
     */
    private static function children(Db $db, string $locale): array
    {
        $children = [];
        foreach ($db->all('SELECT id, parent_id, title FROM pages WHERE locale = ? ORDER BY title, id', [$locale]) as $row) {
            $parent = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];
            $children[$parent][] = ['id' => (int) $row['id'], 'title' => (string) $row['title']];
        }

        return $children;
    }

    /**
     * @param array<int, list<array{id: int, title: string}>> $children
     * @param array<int, true> $seen guards against a cycle already stored by an older
     *                               version or by hand; without it this recurses for ever
     * @return array<int, true>
     */
    private static function descendants(array $children, int $pageId, array $seen = []): array
    {
        $found = [];
        foreach ($children[$pageId] ?? [] as $child) {
            if (isset($seen[$child['id']])) {
                continue;
            }
            $seen[$child['id']] = true;
            $found[$child['id']] = true;
            $found += self::descendants($children, $child['id'], $seen);
        }

        return $found;
    }

    /**
     * @param array<int, list<array{id: int, title: string}>> $children
     * @param array<int, true> $excluded
     * @param list<array{id: int, title: string, depth: int}> $options
     */
    private static function walk(array $children, int $parent, int $depth, array $excluded, array &$options): void
    {
        // A tree deeper than this is a cycle, whatever the rows claim.
        if ($depth > 20) {
            return;
        }
        foreach ($children[$parent] ?? [] as $child) {
            if (isset($excluded[$child['id']])) {
                continue;
            }
            $options[] = ['id' => $child['id'], 'title' => $child['title'], 'depth' => $depth];
            self::walk($children, $child['id'], $depth + 1, $excluded, $options);
        }
    }
}
