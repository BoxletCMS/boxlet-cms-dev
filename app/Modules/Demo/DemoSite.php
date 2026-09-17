<?php

namespace App\Modules\Demo;

use App\Core\Blocks;
use App\Core\Db;
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
                    'style' => SectionStyle::normalize($style),
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
