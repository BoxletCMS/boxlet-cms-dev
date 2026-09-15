<?php

namespace App\Modules\Design;

/**
 * Layer 0: the five characters. Each is a complete set of layer-1 decisions plus the
 * composition it gives a page: the layer-2 section style and layer-3 layout new blocks
 * start from (SPEC §5.4).
 *
 * They differ in structure, not only hue. Tokens change type, scale, spacing, radius,
 * shadow, container and surface contrast; composition changes the shape of the page
 * itself: measure, vertical rhythm, alignment, section boundaries and hero arrangement.
 */
final class Presets
{
    public const DEFAULT = 'minimal';

    public const ALL = [
        // Long-form reading: high-contrast serifs on a wide scale, a narrow measure,
        // generous air, barely softened corners, no shadows, quiet surfaces.
        'editorial' => [
            'seed' => '#8a1c2b', 'secondary' => '#1f1a17', 'typography' => 'editorial', 'scale' => '1.333',
            'spacing' => 'roomy', 'radius' => 'subtle', 'shadow' => 'none', 'container' => 'narrow', 'surface_contrast' => 'low',
        ],
        // Restraint: near-neutral graphite, one sans family on a tight scale, even spacing,
        // barely rounded, flat.
        'minimal' => [
            'seed' => '#3a4250', 'secondary' => '', 'typography' => 'modern', 'scale' => '1.2',
            'spacing' => 'normal', 'radius' => 'subtle', 'shadow' => 'none', 'container' => 'normal', 'surface_contrast' => 'low',
        ],
        // Loud: a condensed-feeling grotesk on the steepest scale, saturated violet,
        // round corners, layered depth, wide sections, strongly separated surfaces.
        'bold' => [
            'seed' => '#6d28d9', 'secondary' => '#1e1045', 'typography' => 'grotesk', 'scale' => '1.5',
            'spacing' => 'normal', 'radius' => 'round', 'shadow' => 'layered', 'container' => 'wide', 'surface_contrast' => 'high',
        ],
        // Gentle: sage green, a rounded family, generous spacing, pill shapes, soft
        // shadows, a pale cream second colour for contrast sections.
        'soft' => [
            'seed' => '#3b6b4f', 'secondary' => '#f5ecdc', 'typography' => 'rounded', 'scale' => '1.25',
            'spacing' => 'generous', 'radius' => 'pill', 'shadow' => 'soft', 'container' => 'normal', 'surface_contrast' => 'medium',
        ],
        // Raw: monospace in capitals, compact spacing on a big scale, hard offset shadows
        // and thick rules, square corners, full-width, pure blue on stark yellow.
        'brutalist' => [
            'seed' => '#1f1fd1', 'secondary' => '#ffe600', 'typography' => 'mono', 'scale' => '1.414',
            'spacing' => 'compact', 'radius' => 'none', 'shadow' => 'hard', 'container' => 'full', 'surface_contrast' => 'high',
        ],
    ];

    /**
     * Layers 2 and 3 per character. `section` is the section style every block starts
     * from, `surfaces` overrides the surface for one block type, and `layouts` picks the
     * arrangement of a block type that offers several. A block type named nowhere takes
     * `section` and its own default layout, so a character keeps working when Slice 9
     * adds six more blocks.
     *
     * The five differ in measure, rhythm, alignment, section boundary and hero
     * arrangement, so switching character changes how a page is composed and not only
     * how it is painted.
     */
    public const COMPOSITION = [
        // A reading column: narrow measure, plenty of air, everything ranged left,
        // no rules between sections. The page is one quiet text.
        'editorial' => [
            'section' => ['surface' => 'plain', 'rhythm' => 'airy', 'width' => 'narrow', 'align' => 'left', 'divider' => 'none'],
            'surfaces' => ['image_text' => 'tinted'],
            'layouts' => ['hero' => 'left', 'image_text' => 'image-left', 'text' => 'single'],
        ],
        // Even and quiet: normal measure and rhythm, centred, every section separated by
        // a hairline rule. Nothing shouts, nothing is full-bleed.
        'minimal' => [
            'section' => ['surface' => 'plain', 'rhythm' => 'normal', 'width' => 'normal', 'align' => 'center', 'divider' => 'line'],
            'surfaces' => [],
            'layouts' => ['hero' => 'center', 'image_text' => 'image-left', 'text' => 'single'],
        ],
        // Big and confident: a wide measure, ranged left so headlines run long, slanted
        // section edges, and the surfaces used hard — gradient hero, contrast panels.
        'bold' => [
            'section' => ['surface' => 'plain', 'rhythm' => 'normal', 'width' => 'wide', 'align' => 'left', 'divider' => 'slant'],
            'surfaces' => ['hero' => 'gradient', 'image_text' => 'contrast', 'text' => 'tinted'],
            'layouts' => ['hero' => 'center', 'image_text' => 'image-left', 'text' => 'single'],
        ],
        // Relaxed and open: airy rhythm, curved section edges, and heroes split so the
        // text sits beside its image rather than under it.
        'soft' => [
            'section' => ['surface' => 'plain', 'rhythm' => 'airy', 'width' => 'normal', 'align' => 'left', 'divider' => 'curve'],
            'surfaces' => ['hero' => 'tinted', 'text' => 'tinted'],
            'layouts' => ['hero' => 'split', 'image_text' => 'image-right', 'text' => 'single'],
        ],
        // Dense and flush: full-bleed sections, tight rhythm, no dividers at all, split
        // heroes and slabs of contrast. Edge to edge, nothing centred.
        'brutalist' => [
            'section' => ['surface' => 'plain', 'rhythm' => 'tight', 'width' => 'full', 'align' => 'left', 'divider' => 'none'],
            'surfaces' => ['hero' => 'contrast', 'text' => 'tinted'],
            'layouts' => ['hero' => 'split', 'image_text' => 'image-left', 'text' => 'columns'],
        ],
    ];

    /**
     * The layer-1 decisions of a character, falling back to the default character.
     *
     * @return array<string, string>
     */
    public static function get(string $name): array
    {
        return self::ALL[$name] ?? self::ALL[self::DEFAULT];
    }

    public static function exists(string $name): bool
    {
        return isset(self::ALL[$name]);
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::ALL);
    }
}
