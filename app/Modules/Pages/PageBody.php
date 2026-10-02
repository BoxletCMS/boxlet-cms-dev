<?php

namespace App\Modules\Pages;

use App\Core\Blocks;
use App\Core\Db;
use App\Modules\Forms\FormBlocks;
use App\Modules\Media\MediaFiles;
use App\Modules\Media\MediaPicture;

/**
 * A published page's sections, drawn: what a visitor's page shows between its header and its
 * footer (PLAN.md D-168).
 *
 * TWO CALLERS, ONE DRAWING. The visitor's page and the Appearance screen's preview drew the
 * same page by two different loops, and the preview's had fallen behind: one band per block,
 * no pictures, no forms — so a section of two columns came apart in the picture the owner
 * judges a design by. Both draw it here now.
 *
 * Every picture, link, form and file the page refers to is resolved up front, one query each,
 * so a template is handed what it needs and never queries.
 */
final class PageBody
{
    /**
     * @param array<int, string> $layouts block type => layout to draw it in instead of its
     *        own: what Apply would give it, for a preview of a character not yet applied
     * @return array{html: string, firstSurface: string} the sections, and what the first one
     *         stands on — the ink on a header laid over it is that section's (D-112)
     */
    public static function draw(Db $db, Blocks $registry, int $pageId, string $locale, string $character, string $appKey, ?int $sent = null, array $layouts = []): array
    {
        $blocks = Page::blocks($db, $pageId);
        foreach ($blocks as $at => $block) {
            // A cover hero keeps its cover, as Apply keeps it (D-120).
            $cover = $block['type'] === 'hero' && str_starts_with($block['layout'], 'cover-');
            if (isset($layouts[$block['type']]) && !$cover) {
                $blocks[$at]['layout'] = $layouts[$block['type']];
            }
        }
        $media = MediaPicture::forBlocks($db, $registry, $locale, $blocks);
        // Links to pages, followed in one query in the page's language (PLAN.md D-034).
        $links = PageLinks::targets($db, $registry, $locale, $blocks);
        // Forms, with what the visitor just did to one (D-046).
        $forms = FormBlocks::resolve($db, $blocks, $locale, $pageId, $appKey, $sent);
        // The files the page's Downloads blocks offer (D-127).
        $files = MediaFiles::forBlocks($db, $registry, $blocks);

        $html = '';
        $first = true;
        $firstSurface = '';
        foreach (Sections::group(Sections::forPage($db, $pageId), $blocks) as $group) {
            $drawable = [];
            foreach ($group['blocks'] as $block) {
                // A block whose type was removed from app/Blocks cannot render; skip it.
                if (!$registry->has($block['type'])) {
                    continue;
                }
                $block['content'] = PageLinks::content($registry, $block['type'], $block['content'], $links);
                $drawable[] = $block;
            }
            // A section whose every block is of a type this install no longer has would be
            // an empty band — the same thing prune() refuses to leave behind on save.
            if ($drawable === []) {
                continue;
            }
            // Only the first section that actually draws is eager: the first picture is
            // usually the one a visitor is waiting to see.
            $html .= SectionRender::draw($registry, $character, $group['section'], $drawable, $media, $first, ['forms' => $forms, 'files' => $files], $locale);
            if ($first) {
                // As it is drawn: a surface left to the character is the character's (D-165).
                $firstSurface = (string) (SectionRender::style($character, $group['section']['style'], $drawable)['surface'] ?? '');
            }
            $first = false;
        }

        return ['html' => $html, 'firstSurface' => $firstSurface];
    }
}
