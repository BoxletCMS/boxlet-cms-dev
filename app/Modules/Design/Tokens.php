<?php

namespace App\Modules\Design;

/**
 * Layer 1: eight decisions, not forty values (SPEC §5.4). Validates them and derives
 * every custom property the site uses from them.
 */
final class Tokens
{
    public const SCALES = ['1.125', '1.2', '1.25', '1.333', '1.414', '1.5'];
    /** Spacing base unit in rem; the whole spacing scale is multiples of it. */
    public const SPACING = ['compact' => 0.875, 'normal' => 1.0, 'roomy' => 1.25, 'generous' => 1.5];
    public const RADIUS = ['none', 'subtle', 'round', 'pill'];
    public const SHADOW = ['none', 'soft', 'hard', 'layered'];
    /*
     * CONTENT WIDTH IS A NUMBER, IN REM (PLAN.md D-062, SPEC §5.4).
     *
     * It was four names, and four names cannot answer "a little narrower than this". The
     * measure — how many characters fit on a line — is the single decision that most changes
     * whether a page is comfortable to read, and it was the one decision the owner could only
     * nudge in jumps of fourteen rem.
     *
     * The four names are still READ: a site saved before this loads with the width its name
     * meant, so nothing has to be migrated and nothing re-chosen.
     */
    public const CONTAINER_NAMES = ['narrow' => 42.0, 'normal' => 56.0, 'wide' => 68.0, 'full' => 80.0];
    public const CONTAINER_MIN = 36.0;
    public const CONTAINER_MAX = 88.0;
    /** Two rem at a time: finer than that is a difference nobody can see. */
    public const CONTAINER_STEP = 2.0;

    /*
     * The base text size, as a factor on the whole type scale (D-062).
     *
     * SIZE AND SCALE ARE DIFFERENT QUESTIONS. The scale is how much bigger each heading is
     * than the one below it; this is how big the text itself is. A site for people who are
     * not twenty-five needs the second, and until now the only way to get it was to pick a
     * scale that made the headings wrong.
     *
     * It moves the TYPE and nothing else — not the spacing, not the corners, not the
     * measure — because a person reaching for "larger text" is asking for larger text.
     */
    public const TEXT_SIZE = ['small' => 0.9375, 'normal' => 1.0, 'large' => 1.0625, 'larger' => 1.125];
    public const SURFACE_CONTRAST = ['low', 'medium', 'high'];

    /*
     * The page itself (PLAN.md D-031).
     *
     * Two values for the header, not a second four-value container decision: the header
     * either holds the same measure as the content or runs edge to edge.
     *
     * BOXED IS 'no'/'yes' RATHER THAN A BOOLEAN, so it is one of the closed-set decisions
     * like every other: design_tokens stores JSON scalars, choices() lists allowed strings
     * and the screen renders a select from them. A bool would be the single exception in
     * all three.
     *
     * The page background is a SHADE FROM THE PALETTE, never a free colour — the reasoning
     * §5.4 uses to refuse a free colour per section. It shows only around a boxed page, so
     * no text ever sits on it and Palette::failures() gains no pairs.
     */
    public const HEADER_WIDTH = ['content', 'full'];
    public const BOXED = ['no', 'yes'];
    public const PAGE_BACKGROUND = ['surface', 'border', 'contrast'];

    /** Type steps as powers of the scale ratio, from small print to the largest heading. */
    /**
     * The closed decisions and their allowed values. The two colours are open (#rrggbb).
     *
     * @return array<string, list<string>>
     */
    public static function choices(): array
    {
        return [
            'typography' => array_keys(Typography::PAIRINGS),
            'scale' => self::SCALES,
            'spacing' => array_keys(self::SPACING),
            'radius' => self::RADIUS,
            'shadow' => self::SHADOW,
            'text_size' => array_keys(self::TEXT_SIZE),
            'surface_contrast' => self::SURFACE_CONTRAST,
            'header_width' => self::HEADER_WIDTH,
            'boxed' => self::BOXED,
            'page_background' => self::PAGE_BACKGROUND,
        ];
    }

    /**
     * Checks submitted decisions: colours as #rrggbb, everything else one of its choices,
     * and every text/background pair of the resulting palette at WCAG AA. Errors are keyed
     * by the decision that caused them; invalid values are replaced by the default preset's
     * so the returned decisions can always be previewed.
     *
     * @param array<mixed> $input
     * @return array{decisions: array<string, string>, errors: array<string, string>}
     */
    public static function validate(array $input): array
    {
        $fallback = Presets::get(Presets::DEFAULT);
        $errors = [];

        $seed = Color::normalizeHex(is_string($input['seed'] ?? null) ? $input['seed'] : '');
        if ($seed === null) {
            $errors['seed'] = t('design.error.color');
        }
        $secondaryInput = is_string($input['secondary'] ?? null) ? trim($input['secondary']) : '';
        $secondary = $secondaryInput === '' ? '' : Color::normalizeHex($secondaryInput);
        if ($secondary === null) {
            $errors['secondary'] = t('design.error.color');
        }
        $decisions = ['seed' => $seed ?? $fallback['seed'], 'secondary' => $secondary ?? ''];

        foreach (self::choices() as $key => $allowed) {
            $value = $input[$key] ?? null;
            if (!is_string($value) || !in_array($value, $allowed, true)) {
                $errors[$key] = t('design.error.choice');
                $value = $fallback[$key];
            }
            $decisions[$key] = $value;
        }

        /*
         * COLOURS SET BY HAND (D-063), one decision per role: '' means "work it out", and a
         * hex means the owner has taken that role over. Empty is the default and the whole
         * palette is derived, exactly as before — this adds a way to disagree, not a new
         * thing to fill in.
         */
        foreach (Palette::BY_HAND as $role) {
            $key = 'color_' . $role;
            $typed = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
            $colour = $typed === '' ? '' : Color::normalizeHex($typed);
            if ($colour === null) {
                $errors[$key] = t('design.error.color');
            }
            $decisions[$key] = $colour ?? '';
        }

        // The one decision that is a number rather than one of a closed set.
        $width = self::width($input['container'] ?? null);
        if ($width === null) {
            $errors['container'] = t('design.error.width', ['min' => self::number(self::CONTAINER_MIN), 'max' => self::number(self::CONTAINER_MAX)]);
        }
        $decisions['container'] = self::number($width ?? self::width($fallback['container']) ?? 56.0);

        /*
         * THE GUARANTEE MOVES FROM DERIVATION TO CHECKING (SPEC §5.4, D-063).
         *
         * Until a colour could be set by hand, the palette could not produce an unreadable
         * pair: every ink was chosen against the surface it would sit on. With hand-set
         * colours it can, so the check below is no longer a formality about the seed — it is
         * the only thing standing between the owner and a site nobody can read. It refuses
         * the same way it always did, and the message now names the control at fault.
         */
        $byHand = self::byHand($decisions);
        $colors = Palette::colors($decisions['seed'], $decisions['secondary'], $decisions['surface_contrast'], $byHand);
        foreach (Palette::failures($colors, $decisions['secondary'] !== '', $byHand) as $failure) {
            $message = t('design.error.contrast', [
                'pair' => t('design.pair.' . $failure['pair']),
                'ratio' => number_format($failure['ratio'], 2),
                'required' => number_format($failure['required'], 1),
            ]);
            $key = $failure['decision'];
            $errors[$key] = isset($errors[$key]) ? $errors[$key] . ' ' . $message : $message;
        }

        /*
         * ONE CANONICAL ORDER, whatever order the loops above happened to fill them in.
         * What is stored — and what a test compares against a character — should not depend
         * on which validation ran first; `container` stopped being one of the closed sets
         * (D-062) and moved to the end of the array without anything intending it to.
         *
         * `+ $decisions` keeps anything this list forgets, so a decision added later is
         * mis-ordered rather than lost.
         */
        $order = array_merge(
            ['seed', 'secondary'],
            array_map(static fn (string $role): string => 'color_' . $role, Palette::BY_HAND),
            ['typography', 'text_size', 'scale', 'spacing', 'radius', 'shadow', 'container',
                'surface_contrast', 'header_width', 'boxed', 'page_background'],
        );
        $ordered = [];
        foreach ($order as $key) {
            if (array_key_exists($key, $decisions)) {
                $ordered[$key] = $decisions[$key];
            }
        }

        return ['decisions' => $ordered + $decisions, 'errors' => $errors];
    }

    /**
     * The same decisions in numbers a person can read, for the lines under the controls
     * (PLAN.md D-058).
     *
     * WHAT WAS THERE BEFORE WAS CSS. The screen printed
     * `clamp(2.038rem, 1.508rem + 1.759vw, 2.827rem)` and a row of rem values, which is the
     * compiler's own language leaking onto the owner's screen: it told a person who has
     * never written a stylesheet nothing, and it told one who has nothing they could not
     * read off the page itself.
     *
     * PIXELS AT THE BROWSER'S DEFAULT of 16px to the rem. That is an assumption, and it is
     * the right one to make here: a reader who has changed it is reading a page whose every
     * size moves with their setting, and the number is a sense of scale, not a promise.
     *
     * The specimen is the preview beside these lines — the admin cannot show one, because
     * its own type is fixed by the --ui-* set and must never follow the site's (SPEC §5.4).
     *
     * @param array<string, string> $decisions validated decisions
     * @return array{text: array<string, int>, text_phone: int, space: int, section: int, radius: int, container: int, container_rem: float}
     */
    public static function readable(array $decisions): array
    {
        $px = static fn (float $rem): int => (int) round($rem * 16);
        $ratio = (float) $decisions['scale'];
        $base = self::TEXT_SIZE[$decisions['text_size']] ?? 1.0;
        $sizes = [];
        foreach (Derived::TYPE_STEPS as $name => $step) {
            $sizes[$name] = $px($base * $ratio ** $step);
        }
        $unit = self::SPACING[$decisions['spacing']];
        $width = self::width($decisions['container']) ?? 56.0;

        return [
            'text' => $sizes,
            // What the largest heading shrinks to on a narrow screen, by the same rule
            // typeScale() uses to build the clamp.
            'text_phone' => $px(max(1.25, $base * $ratio ** 5 * 0.72)),
            'space' => $px($unit),
            'section' => $px($unit * Derived::SPACE_STEPS['2xl']),
            'radius' => $px((float) rtrim(Derived::RADII[$decisions['radius']]['m'], 'rem')),
            'container' => $px($width),
            'container_rem' => $width,
        ];
    }

    /**
     * The colours the owner has taken over, role => hex, leaving out the ones left to the
     * palette. One reading of the decisions, so nothing has to know the key's shape twice.
     *
     * @param array<string, string> $decisions
     * @return array<string, string>
     */
    public static function byHand(array $decisions): array
    {
        $byHand = [];
        foreach (Palette::BY_HAND as $role) {
            $value = $decisions['color_' . $role] ?? '';
            if ($value !== '') {
                $byHand[$role] = $value;
            }
        }

        return $byHand;
    }

    /**
     * A content width as a number of rem: one of the four old names, or a number within the
     * bounds, rounded to the step. Null for anything else, which is what makes it an error
     * rather than a silent default.
     */
    public static function width(mixed $value): ?float
    {
        if (is_string($value) && isset(self::CONTAINER_NAMES[$value])) {
            return self::CONTAINER_NAMES[$value];
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $rem = (float) $value;
        if ($rem < self::CONTAINER_MIN || $rem > self::CONTAINER_MAX) {
            return null;
        }

        return round($rem / self::CONTAINER_STEP) * self::CONTAINER_STEP;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
