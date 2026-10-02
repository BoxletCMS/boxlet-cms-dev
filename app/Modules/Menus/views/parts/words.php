<?php

/**
 * The owner's words in the header and footer, per language (PLAN.md D-111, D-180). Required
 * by navigation.php, in its scope.
 *
 * @var array<string, array<string, mixed>> $words
 * @var array<int, array<string, mixed>> $locales
 * @var string $shownLocale the site's main language, which stands open
 */

/** A stored word of the owner's, for one language; '' when there is none. */
$word = static fn (string $code, string $field): string => is_string($words[$code][$field] ?? null)
    ? $words[$code][$field]
    : '';

/**
 * ONE LANGUAGE'S WORDS, FOLDED (D-111). With one language the words stand as they are. With
 * more, each language is a <details>, and the site's main one stands open. A <details> works
 * without a script, and a language folded away is still on the form and still saved.
 */
$wordsPanel = static function (string $code, string $legend, string $inside) use ($locales, $shownLocale): string {
    $label = '';
    foreach ($locales as $locale) {
        if ((string) $locale['code'] === $code) {
            $label = (string) $locale['label'];
        }
    }
    if (count($locales) < 2) {
        return '<div class="words-one">' . $inside . '</div>';
    }

    return '<details class="fieldset words"' . ($code === $shownLocale ? ' open' : '') . '>'
        . '<summary>' . e($legend . ': ' . $label) . '</summary>'
        . '<div class="words-inside">' . $inside . '</div>'
        . '</details>';
};
