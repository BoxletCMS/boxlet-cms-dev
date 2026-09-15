<?php

namespace App\Modules\Design;

use InvalidArgumentException;
use RuntimeException;

/**
 * Compiles design token values into CSS custom properties. tokens.css is never a
 * static file: it only ever comes from here.
 */
final class TokenCompiler
{
    /**
     * Values are validated here rather than trusted from the type: from Slice 4 they
     * come from the design_tokens table.
     *
     * @param array<mixed> $tokens group => name => scalar value, emitted as --group-name
     */
    public function compile(array $tokens, string $target): void
    {
        $lines = [];
        foreach ($tokens as $group => $values) {
            if (!is_array($values)) {
                throw new InvalidArgumentException("Token group {$group} must be an array");
            }
            foreach ($values as $name => $value) {
                $property = $group . '-' . $name;
                if (!preg_match('~^[a-z][a-z0-9]*(-[a-z0-9]+)+$~', $property)) {
                    throw new InvalidArgumentException("Invalid token name: {$property}");
                }
                if (!is_scalar($value) || preg_match('~[;{}<>\\\\]~', (string) $value)) {
                    throw new InvalidArgumentException("Invalid value for token {$property}");
                }
                $lines[] = sprintf('  --%s: %s;', $property, $value);
            }
        }

        $css = "/* Generated from design tokens. Do not edit; changes are overwritten. */\n"
            . ":root {\n" . implode("\n", $lines) . "\n}\n";

        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create directory {$directory}");
        }
        $temporary = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temporary, $css) === false || !rename($temporary, $target)) {
            throw new RuntimeException("Cannot write {$target}");
        }
    }
}
