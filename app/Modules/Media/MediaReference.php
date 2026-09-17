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
     * The pictures a field may choose from, newest first: id, library name, and the
     * thumbnail to show for the one currently chosen.
     *
     * The page editors need this to offer a choice rather than ask for a number, and they
     * are the wrong place to know how pictures are stored — so it lives here, beside the
     * rule about what a reference means.
     *
     * The thumbnail URL is built HERE and never in the browser. Media URLs use named
     * presets only (SPEC §6); a picker that assembled one from an id and a filename would
     * be a second implementation of that contract, in JavaScript, free to drift from it.
     * A picture whose thumbnail has not been generated yet returns null and the picker
     * shows its name alone.
     *
     * @return list<array{id: int, name: string, thumb: string|null}>
     */
    public static function choices(Db $db, int $limit = 200): array
    {
        $choices = [];
        foreach ($db->all('SELECT id, filename, variants_json FROM media ORDER BY id DESC LIMIT ' . $limit) as $row) {
            $choices[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['filename'],
                'thumb' => MediaVariants::url($row, 'thumb'),
            ];
        }

        return $choices;
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
