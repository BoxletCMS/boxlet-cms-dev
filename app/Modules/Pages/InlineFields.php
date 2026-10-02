<?php

namespace App\Modules\Pages;

use App\Core\Blocks;
use App\Core\Db;

/**
 * WHAT TYPING ON THE PAGE NEEDS TO KNOW (PLAN.md D-178, README 4.4), handed to builder-inline.js
 * with the document: each block type's fields — their type, whether they are required and, for
 * rich text, what they allow — the item a repeater's "+" adds, and the pages a link may lead
 * to. The canvas names a field by its path (`heading`, `items.2.heading`); this says what kind
 * of field that is, so the browser never guesses from the markup.
 */
final class InlineFields
{
    /**
     * @return array{fields: array<string, array<string, mixed>>, items: array<string, array<string, mixed>>, pages: list<array{ref: string, title: string, depth: int, url: string}>}
     */
    public static function of(Db $db, Blocks $registry, string $locale): array
    {
        $say = static fn (string $key): string => site_t($key, $locale, 'samples');
        $fields = [];
        $items = [];
        foreach ($registry->types() as $type) {
            foreach ($registry->get($type)['fields'] as $name => $field) {
                $fields[$type][$name] = self::describe($type, (string) $name, $field);
                if ($field['type'] === 'repeater') {
                    foreach ($field['fields'] as $sub => $declared) {
                        $fields[$type][$name]['fields'][$sub] = self::describe($type, $name . '.' . $sub, $declared);
                    }
                    // What "+" adds: one item saying what it is for, as a new block's do (D-176).
                    $sampled = $registry->sampled($type, $say)[$name] ?? [];
                    $items[$type][$name] = is_array($sampled) && isset($sampled[0]) ? $sampled[0] : Blocks::emptyItem($field);
                }
            }
        }
        $pages = [];
        foreach (PageLinks::choices($db, $locale) as $group => $choice) {
            $pages[] = ['ref' => PageLinks::to($group), 'title' => $choice['title'], 'depth' => $choice['depth'], 'url' => $choice['url']];
        }

        return ['fields' => $fields, 'items' => $items, 'pages' => $pages];
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function describe(string $type, string $path, array $field): array
    {
        return [
            'type' => $field['type'],
            'required' => $field['required'] ?? false,
            'inline' => $field['inline'] ?? false,
            'label' => t('block.' . $type . '.' . $path),
        ] + (isset($field['allow']) ? ['allow' => $field['allow']] : []) + (isset($field['max']) ? ['max' => $field['max']] : []);
    }
}
