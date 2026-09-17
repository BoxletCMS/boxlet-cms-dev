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

/** The admin's own language. Slice 6 adds locales for the SITE; the admin has one. */
const ADMIN_LANG = 'en';

/**
 * Admin UI string from lang/{locale}/ with :name placeholders replaced. A missing key
 * returns the key itself, so it shows up rather than rendering blank.
 *
 * @param array<string, string|int> $replace
 */
function t(string $key, array $replace = []): string
{
    static $strings = null;
    if ($strings === null) {
        // Every file in the admin locale's directory, not a list of names: the strings
        // were split by concern when one file passed the 300-line rule, and a loader that
        // names its files means editing this function every time another concern earns
        // one. A key defined in two files is a test failure (tests/lang_test.php), not a
        // silent win for whichever loads first.
        //
        // Nested by locale, so that Slice 6 adding lang/hr/ cannot merge Croatian into
        // English by sitting beside it. ADMIN_LANG is the one locale there is today; it
        // is a constant rather than a setting, because switching it is a feature nobody
        // has asked for yet.
        $strings = [];
        foreach (glob(dirname(__DIR__, 2) . '/lang/' . ADMIN_LANG . '/*.php') ?: [] as $file) {
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
