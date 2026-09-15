<?php

namespace App\Modules\Design;

/**
 * Layer 0: the five characters. Each is a complete set of layer-1 decisions; applying
 * one copies its values, and nothing refers back to the preset afterwards.
 *
 * They differ in structure, not only hue: type pairing and scale, spacing rhythm,
 * radius, shadow, container width and surface contrast all change between them.
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
     * @return array<string, string>
     */
    public static function get(string $name): array
    {
        return self::ALL[$name] ?? self::ALL[self::DEFAULT];
    }
}
