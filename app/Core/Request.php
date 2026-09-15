<?php

namespace App\Core;

final class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers  lower-case names
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $basePath,
        public readonly array $query,
        public readonly array $body,
        public readonly array $headers,
    ) {
    }

    public static function fromGlobals(): self
    {
        $query = $_GET;
        $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        $basePath = rtrim($scriptDir, '/');

        // index.php?route=/en/hello is the fallback for hosts without mod_rewrite.
        if (isset($query['route']) && is_string($query['route'])) {
            $path = $query['route'];
            unset($query['route']);
        } else {
            $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
                $path = substr($path, strlen($basePath));
            }
            if (str_starts_with($path, '/index.php')) {
                $path = substr($path, strlen('/index.php'));
            }
            $path = rawurldecode($path);
        }

        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with((string) $name, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH'] as $name) {
            if (isset($_SERVER[$name])) {
                $headers[strtolower(str_replace('_', '-', $name))] = (string) $_SERVER[$name];
            }
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            '/' . ltrim($path, '/'),
            $basePath,
            $query,
            $_POST,
            $headers,
        );
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }
}
