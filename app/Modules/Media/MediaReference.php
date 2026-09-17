<?php

namespace App\Modules\Media;

use App\Core\Blocks;
use App\Core\Db;

/**
 * Which block fields hold a picture, and what becomes of an id that names none.
 *
 * A media id is a plain integer inside page_blocks.content_json, and nothing in the
 * database enforces that it points anywhere — a foreign key would have to reach inside
 * JSON. So the rule is the one D-024 set for a section's background picture, applied to
 * block content as well: AN ID THAT NAMES NO PICTURE BECOMES NULL ON SAVE.
 *
 * The failure it prevents is not hypothetical. The demo shipped image => 1..6 for
 * pictures that were never uploaded, so on a fresh install the first photograph put into
 * the library was silently adopted by a demo page that never meant it — and could then
 * not be deleted, because a page "used" it.
 *
 * Deliberately NOT part of Blocks::normalize(), which is pure and called from places with
 * no database: the same split as SectionStyle::normalize() and ::resolve(). On RENDER
 * nothing is resolved — the renderer looks the picture up, finds nothing and shows the
 * placeholder, which is the behaviour that already existed.
 */
final class MediaReference
{
    /**
     * Block type => the names of its media fields, taken from the registry rather than a
     * list kept here, so a block added later is covered without anyone remembering to.
     *
     * @return array<string, list<string>>
     */
    public static function fields(Blocks $registry): array
    {
        $fields = [];
        foreach ($registry->types() as $type) {
            foreach ($registry->get($type)['fields'] as $name => $field) {
                if (($field['type'] ?? '') === 'media') {
                    $fields[$type][] = (string) $name;
                }
            }
        }

        return $fields;
    }

    /**
     * The same content with every media id that names no picture set to null.
     *
     * @param array<string, mixed> $content normalized
     * @return array<string, mixed>
     */
    public static function resolve(Db $db, Blocks $registry, string $type, array $content): array
    {
        foreach (self::fields($registry)[$type] ?? [] as $field) {
            // Only fields the content actually carries: this never adds a key that
            // normalize() did not put there.
            if (!array_key_exists($field, $content)) {
                continue;
            }
            $id = $content[$field];
            if (!is_int($id) || $id <= 0) {
                $content[$field] = null;
                continue;
            }
            if ($db->one('SELECT id FROM media WHERE id = ?', [$id]) === null) {
                $content[$field] = null;
            }
        }

        return $content;
    }
}
