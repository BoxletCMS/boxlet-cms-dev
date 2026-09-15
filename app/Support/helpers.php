<?php

/**
 * Escape a value for HTML text and attribute context.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Read an environment variable loaded from .env, casting "true", "false" and "null".
 */
function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
    if (!is_string($value)) {
        return $value ?? $default;
    }

    return match (strtolower($value)) {
        'true' => true,
        'false' => false,
        'null', '' => $default,
        default => $value,
    };
}
