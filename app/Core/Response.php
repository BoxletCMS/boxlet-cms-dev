<?php

namespace App\Core;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * HTML for admin and installer pages: never cached, never framed, and a CSP that
     * allows no inline scripts or styles (SPEC §6).
     */
    public static function admin(string $body, int $status = 200): self
    {
        $response = self::html($body, $status);
        $response->headers += [
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; form-action 'self'; "
                . "frame-ancestors 'none'; base-uri 'none'",
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Cache-Control' => 'no-store',
        ];

        return $response;
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }
}
