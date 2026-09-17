<?php

namespace App\Modules\Demo;

use App\Core\Blocks;
use App\Core\Db;
use App\Modules\Design\Composition;
use App\Modules\Design\SectionStyle;
use App\Modules\Pages\Page;
use App\Support\RichText;
use RuntimeException;

/**
 * The demo ("golden") site: published pages that between them use every block type,
 * every layout and every section style value. A new install can start from it, and
 * `php migrations/seed.php` adds it to an empty site, so any change to blocks or design
 * can be checked visually in seconds.
 */
final class DemoSite
{
    /**
     * Creates the demo pages in $locale. Refuses a site that already has pages rather
     * than mixing demo content into real content.
     *
     * @return int the number of pages created
     */
    public static function seed(Db $db, Blocks $registry, string $locale): int
    {
        if ((int) ($db->one('SELECT COUNT(*) AS n FROM pages')['n'] ?? 0) > 0) {
            throw new RuntimeException('The site already has pages. The demo is only added to a site without any.');
        }

        // What a person using the editor would get. A seed that stored only the keys it
        // names left every other key at the CLOSED-SET DEFAULT — normal, left, none — which
        // is not what the active character composes, so every demo section counted as
        // hand-tuned and announced itself: measured at 21 of 21 panels open, with rhythm
        // differing in 16 and align in 13 purely from defaults nobody chose.
        $character = Composition::active($db);

        $pages = self::pages();
        foreach ($pages as $page) {
            $id = Page::create($db, $registry, $locale, $page['title'], $page['slug'], null, []);
            $blocks = [];
            foreach ($page['blocks'] as [$type, $content, $style, $layout]) {
                foreach ($registry->get($type)['fields'] as $name => $field) {
                    if ($field['type'] === 'richtext' && is_string($content[$name] ?? null)) {
                        $content[$name] = RichText::sanitize($content[$name]);
                    }
                }
                $blocks[] = [
                    'id' => null,
                    'type' => $type,
                    'content' => $registry->normalize($type, $content),
                    // The keys the seed names win; every other key comes from the character's
                    // composition for this block type. `+` keeps the left-hand value, which
                    // is exactly that rule.
                    'style' => SectionStyle::normalize($style + Composition::style($character, $type)),
                    'layout' => $registry->layout($type, $layout),
                ];
            }
            Page::update($db, $registry, $id, [
                'title' => $page['title'],
                'slug' => $page['slug'],
                'parent_id' => null,
                'status' => 'draft',
            ], $blocks);
            Page::setStatus($db, $id, true);
        }

        return count($pages);
    }

    /**
     * @return list<array{slug: string, title: string, blocks: list<array{string, array<string, mixed>, array<string, string>, string}>}>
     */
    public static function pages(): array
    {
        return require __DIR__ . '/pages.php';
    }
}
