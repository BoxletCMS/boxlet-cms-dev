<?php

/**
 * Escape a value for HTML text and attribute context.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Read a variable from .env or the real environment. Returns the raw string: nothing
 * is cast, so a password spelled "true" stays a string. Empty values count as missing.
 */
function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;

    return is_string($value) && $value !== '' ? $value : $default;
}

/**
 * Admin UI string from lang/ with :name placeholders replaced. A missing key
 * returns the key itself, so it shows up rather than rendering blank.
 *
 * @param array<string, string|int> $replace
 */
function t(string $key, array $replace = []): string
{
    static $strings = null;
    if ($strings === null) {
        // Every file in lang/, not a list of names: en.php was split by concern when it
        // passed the 300-line rule, and a loader that names its files means editing this
        // function every time another concern earns one. A key defined in two files is a
        // test failure (tests/lang_test.php), not a silent win for whichever loads first.
        $strings = [];
        foreach (glob(dirname(__DIR__, 2) . '/lang/*.php') ?: [] as $file) {
            $part = require $file;
            if (is_array($part)) {
                $strings += $part;
            }
        }
    }
    $text = is_array($strings) && is_string($strings[$key] ?? null) ? $strings[$key] : $key;
    foreach ($replace as $name => $value) {
        $text = str_replace(':' . $name, (string) $value, $text);
    }

    return $text;
}
