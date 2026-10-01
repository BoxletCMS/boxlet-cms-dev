<?php

/**
 * The owner's words in the header and footer, per language (PLAN.md D-111). Required by
 * appearance.php, in its scope. They stay on this screen until they move to Navigation
 * (the redesign's phase 5); the look around them is what the sections are about.
 *
 * @var array<string, array<string, mixed>> $words
 * @var array<int, array<string, mixed>> $locales
 * @var string $shownLocale
 */

/** A stored word of the owner's, for one language; '' when there is none. */
$word = static fn (string $code, string $field): string => is_string($words[$code][$field] ?? null)
    ? $words[$code][$field]
    : '';

/**
 * ONE LANGUAGE'S WORDS, FOLDED (D-111). With one language the words are a group like any
 * other. With more, each language is a <details>, and the one the picture is drawn in
 * stands open. A <details> works without a script, and a language folded away is still on
 * the form and still saved.
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
    $previewed = $code === $shownLocale;

    return '<details class="fieldset words"' . ($previewed ? ' open' : '') . '>'
        . '<summary>' . e($legend . ': ' . $label) . ($previewed ? ' <span class="words-previewed">' . e(t('appearance.words_previewed')) . '</span>' : '') . '</summary>'
        . '<div class="words-inside">' . $inside . '</div>'
        . '</details>';
};
