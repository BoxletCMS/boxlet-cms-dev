<?php

namespace App\Modules\Demo;

use App\Core\BlockOptions;
use App\Core\Blocks;
use App\Core\Db;
use App\Modules\Design\Composition;
use App\Modules\Design\SectionStyle;
use App\Modules\Forms\Form;
use App\Modules\Languages\Locales;
use App\Modules\Menus\Menu;
use App\Modules\Pages\Page;
use App\Modules\Pages\PageSeo;
use App\Modules\Pages\PageLinks;
use App\Modules\Pages\SectionForm;
use App\Modules\Pages\Translations;
use App\Modules\Settings\SiteChrome;
use App\Support\RichText;
use RuntimeException;

/**
 * The demo site (PLAN.md D-167, README 1.6): Atelier Lumen, an interior studio. Its home page
 * is the page the builder's mockup shows; four pages behind it, a menu over them, and the
 * home page translated, so a translation is always there to look at. One more page, not in
 * the menu, shows every block in every layout: the visual regression fixture.
 *
 * A new install can start from it, and `php migrations/seed.php` adds it to an empty site.
 */
final class DemoSite
{
    /**
     * The character the demo is written for (README 1.6: the mockup at its Soft-like set). A
     * section or block stores only where it differs from this one's composition.
     */
    public const CHARACTER = 'soft';

    /**
     * Creates the demo in $locale: Croatian words for a Croatian site, English for any other.
     * Refuses a site that already has pages rather than mixing demo content into real content.
     *
     * @return int the number of pages created in $locale (the translation not counted)
     */
    public static function seed(Db $db, Blocks $registry, string $locale): int
    {
        if ((int) ($db->one('SELECT COUNT(*) AS n FROM pages')['n'] ?? 0) > 0) {
            throw new RuntimeException('The site already has pages. The demo is only added to a site without any.');
        }

        $lang = $locale === 'hr' ? 'hr' : 'en';
        $pages = self::pages($lang);
        // Every page first, so a link can refer to one seeded after it (PLAN.md D-034).
        $ids = [];
        foreach ($pages as $page) {
            $ids[$page['key']] = Page::create($db, $registry, $locale, $page['title'], $page['slug'], null, []);
        }
        // One contact form, in the demo's language, for every Form block the seed holds.
        $form = Form::create($db, $locale, $lang === 'hr' ? 'Kontakt' : 'Contact');

        foreach ($pages as $page) {
            self::fill($db, $registry, $ids[$page['key']], $page, $ids, $form);
            Page::setStatus($db, $ids[$page['key']], true);
        }
        self::menu($db, $locale, $pages, $ids);
        self::translateHome($db, $registry, $ids, $lang === 'hr' ? 'en' : 'hr');
        // Its sections store only what differs from this character, so they are drawn with it
        // (the owner's review of D-170). The caller compiles the design: Installer does.
        Composition::remember($db, self::CHARACTER);

        return count($pages);
    }

    /**
     * One page's sections and blocks, written as the builder writes them.
     *
     * @param array{key: string, slug: string, title: string, description: string, menu: bool, sections: list<array{style: array<string, string>, layout: string, blocks: list<array{string, array<string, mixed>, string, array<string, string>, int}>}>} $page
     * @param array<string, int> $ids page key => id
     */
    private static function fill(Db $db, Blocks $registry, int $id, array $page, array $ids, int $form): void
    {
        $reference = static fn (array $match): string => isset($ids[$match[1]]) ? PageLinks::to($ids[$match[1]]) : $match[0];
        $sections = [];
        $blocks = [];
        foreach ($page['sections'] as $at => $section) {
            $key = SectionForm::key(null, $at);
            $sections[] = [
                'key' => $key,
                'id' => null,
                'layout' => $section['layout'],
                'stack' => null,
                'style' => self::ownStyle($section['style'], array_column($section['blocks'], 0)),
            ];
            foreach ($section['blocks'] as [$type, $content, $layout, $options, $column]) {
                $content = self::link($registry->get($type)['fields'], $content, $reference);
                if (($content['form'] ?? null) === 'demo:form') {
                    $content['form'] = $form;
                }
                $blocks[] = [
                    // Never rendered in an editor: the key only has to exist and differ.
                    'key' => \App\Modules\Pages\BlockForm::key(null, count($blocks)),
                    'id' => null,
                    'type' => $type,
                    'content' => $registry->normalize($type, $content),
                    'style' => SectionStyle::normalize([]),
                    'options' => self::ownOptions($registry, $type, $options),
                    'layout' => $registry->layout($type, $layout),
                    'section' => $key,
                    'column' => $column,
                ];
            }
        }
        Page::update($db, $registry, $id, [
            'title' => $page['title'],
            'slug' => $page['slug'],
            'parent_id' => null,
            'status' => 'draft',
            // The showroom is for looking at, not for finding (D-170).
            'seo_json' => PageSeo::json(['title' => '', 'description' => $page['description'], 'noindex' => $page['key'] === 'blocks']),
        ], $blocks, $sections);
    }

    /**
     * A section's style as an owner who means it would leave it (D-165, the owner's review of
     * D-170): the keys the page names, less every one that only says what the demo's character
     * composes anyway. Stored, such a value is "styled by hand" — loading another character
     * asked about thirty sections on a fresh install, and kept them looking like Soft.
     *
     * @param array<string, string> $style
     * @param list<string> $types the section's block types
     * @return array<string, string|int|null>
     */
    private static function ownStyle(array $style, array $types): array
    {
        $own = SectionStyle::normalize($style);
        $composed = Composition::section(self::CHARACTER, $types);
        foreach ($composed as $name => $value) {
            if (array_key_exists($name, $own) && $own[$name] !== '' && (string) $own[$name] === (string) $value) {
                $own[$name] = '';
            }
        }

        return $own;
    }

    /**
     * A block's options the same way: only those the character would not answer so already,
     * its composition's for the type or else the option's own default.
     *
     * @param array<string, string> $options
     * @return array<string, string>
     */
    private static function ownOptions(Blocks $registry, string $type, array $options): array
    {
        $specs = $registry->get($type)['options'];
        $composed = Composition::options(self::CHARACTER, $type);
        $own = [];
        foreach (BlockOptions::normalize($specs, $options) as $name => $value) {
            $answer = BlockOptions::clean($specs[$name], $composed[$name] ?? '');
            if ($value !== '' && $value !== ($answer !== '' ? $answer : $specs[$name]['default'])) {
                $own[$name] = $value;
            }
        }

        return $own;
    }

    /**
     * A navigation menu over the demo's pages, the services on the home page first — by
     * its anchor, `/#usluge`, which is what an anchor is for — and the chrome pointed at it.
     *
     * WITHOUT THIS THE DEMO HAS NO HEADER AT ALL. An empty header is deliberately not drawn
     * (PLAN.md D-032); the demo exists to be a site worth looking at, and a site without
     * navigation is not one.
     *
     * @param list<array{key: string, slug: string, title: string, description: string, menu: bool, sections: list<array{style: array<string, string>, layout: string, blocks: list<array{string, array<string, mixed>, string, array<string, string>, int}>}>}> $pages
     * @param array<string, int> $ids page key => id
     */
    private static function menu(Db $db, string $locale, array $pages, array $ids): void
    {
        $menu = Menu::create($db, $locale, 'Main');
        $anchor = (string) ($pages[0]['sections'][1]['style']['anchor'] ?? '');
        if ($anchor !== '') {
            Menu::addItem($db, $menu, null, null, '/#' . $anchor, $locale === 'hr' ? 'Što radimo' : 'What we do');
        }
        foreach ($pages as $page) {
            if ($page['menu']) {
                Menu::addItem($db, $menu, null, $ids[$page['key']], null, null);
            }
        }
        SiteChrome::saveShared($db, 'Main');
    }

    /**
     * The home page in the other of the demo's two languages (README 1.6), so the site always
     * has a translation to show: the language added, the page translated as the owner would,
     * and its words replaced with that language's. Its menu leads to its own page's services.
     */
    /**
     * @param array<string, int> $ids page key => id, in the site's own language
     */
    private static function translateHome(Db $db, Blocks $registry, array $ids, string $other): void
    {
        $homeId = $ids['home'];
        if ($db->one('SELECT code FROM locales WHERE code = ?', [$other]) === null) {
            Locales::add($db, $other);
        }
        $translated = Translations::create($db, $registry, $homeId, $other);
        if (!is_int($translated)) {
            return;
        }
        $home = self::pages($other)[0];
        // The translation holds the same sections and blocks in the same order; only the
        // words differ, so each block takes the other language's content by position.
        $words = [];
        foreach ($home['sections'] as $section) {
            foreach ($section['blocks'] as [$type, $content]) {
                $words[] = [$type, $content];
            }
        }
        $blocks = Page::editable($db, $registry, $translated);
        // Its links lead to the site's own pages: only the home page is translated.
        $reference = static fn (array $match): string => isset($ids[$match[1]]) ? PageLinks::to($ids[$match[1]]) : $match[0];
        foreach ($blocks as $at => $block) {
            [$type, $content] = $words[$at] ?? [$block['type'], []];
            if ($type === $block['type']) {
                $blocks[$at]['content'] = $registry->normalize($type, self::link($registry->get($type)['fields'], $content, $reference));
            }
        }
        // And each section's name and anchor in that language: the copy kept the source's.
        $sections = Page::editableSections($db, $translated);
        foreach ($sections as $at => $section) {
            foreach (['name', 'anchor'] as $own) {
                $sections[$at]['style'][$own] = (string) ($home['sections'][$at]['style'][$own] ?? '');
            }
        }
        Page::update($db, $registry, $translated, [
            'title' => $home['title'],
            'slug' => '',
            'parent_id' => null,
            'status' => 'published',
            'seo_json' => PageSeo::json(['title' => '', 'description' => $home['description']]),
        ], $blocks, $sections);
        $menu = Menu::create($db, $other, 'Main');
        Menu::addItem($db, $menu, null, $translated, null, null);
        // The home page of a language that is not the site's first is under its code. Written
        // out: Url::page() answers from the request, and a seed has none.
        Menu::addItem($db, $menu, null, null, '/' . $other . '/#' . (string) ($home['sections'][1]['style']['anchor'] ?? ''), $other === 'hr' ? 'Što radimo' : 'What we do');
    }

    /**
     * The seed's `demo:{slug}` links turned into page references, in link fields and rich
     * text alike, and inside a repeater's items the same way as at the top: the Columns
     * block's links would otherwise have been stored as `demo:about`, which no link rule
     * accepts, and drawn as nothing.
     *
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $content
     * @param callable(array<int|string, string>): string $reference
     * @return array<string, mixed>
     */
    private static function link(array $fields, array $content, callable $reference): array
    {
        foreach ($fields as $name => $field) {
            if ($field['type'] === 'link' && is_array($content[$name] ?? null) && is_string($content[$name]['url'] ?? null)) {
                $content[$name]['url'] = (string) preg_replace_callback('~^demo:([a-z0-9-]*)$~', $reference, $content[$name]['url']);
            }
            if ($field['type'] === 'richtext' && is_string($content[$name] ?? null)) {
                $linked = (string) preg_replace_callback('~(?<=href=")demo:([a-z0-9-]*)(?=")~', $reference, $content[$name]);
                $content[$name] = RichText::sanitize($linked);
            }
            if ($field['type'] === 'repeater' && is_array($content[$name] ?? null)) {
                foreach ($content[$name] as $i => $item) {
                    $content[$name][$i] = is_array($item) ? self::link($field['fields'], $item, $reference) : $item;
                }
            }
        }

        return $content;
    }

    /**
     * The demo's pages in one of its two languages.
     *
     * @return list<array{key: string, slug: string, title: string, description: string, menu: bool, sections: list<array{style: array<string, string>, layout: string, blocks: list<array{string, array<string, mixed>, string, array<string, string>, int}>}>}>
     */
    public static function pages(string $lang = 'en'): array
    {
        return (require __DIR__ . '/pages.php')($lang === 'hr' ? 'hr' : 'en');
    }
}
