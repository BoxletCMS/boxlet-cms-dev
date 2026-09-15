# Boxlet

A small self-hosted PHP CMS for people who build many small sites.

## Requirements

- PHP 8.1 or newer
- Extensions: pdo, pdo_sqlite, mbstring, fileinfo, json
- Composer (development only; release ZIPs ship with `vendor/`)

## Development server

```sh
composer install
cp .env.example .env        # set APP_DEBUG=true for readable error traces
php -S localhost:8000 -t public
```

Then open <http://localhost:8000/hello> (English, the primary locale, no prefix) or
<http://localhost:8000/hr/hello> (Croatian). `/en/hello` redirects to `/hello`.

## Tests

Run `php tests/run.php` (no web server or database needed; exits non-zero on failure).

Static analysis: `composer install` (includes dev tools), then `vendor/bin/phpstan analyse`.

## Deployment

In every case the document root must point at `public/`. `app/`, `config/`,
`storage/` and `vendor/` must not be reachable from the web.

### Apache (shared hosting)

Set the document root to `public/`. `public/.htaccess` rewrites every request that
is not a real file to `index.php`.

If the host has no `mod_rewrite`, set `APP_PRETTY_URLS=false` in `.env`; generated
links then take the form `index.php?route=/en/hello`.

The `.htaccess` files containing `Require all denied` in `app/`, `config/` and
`storage/` are a safety net for hosts that cannot move the document root. They are
not a substitute for it.

### Nginx

Set the document root to `public/` and route unknown paths to the front controller:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

Nginx ignores `.htaccess` files entirely, so the document root is the only
protection for `app/`, `config/` and `storage/`.

## License

MIT, see [LICENSE](LICENSE).
