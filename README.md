# Boxlet

A small self-hosted PHP CMS for people who build many small sites.

## Requirements

- PHP 8.1 or newer
- Extensions: pdo, mbstring, fileinfo, json, session, and pdo_mysql or pdo_sqlite
- MySQL/MariaDB with a `utf8mb4` database (recommended), or SQLite for small
  single-site installs
- URL rewriting: Apache `mod_rewrite` or nginx `try_files` (required, see Deployment)
- Composer (development only; release ZIPs ship with `vendor/`)

## Development server

```sh
composer install
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8000 -t public
```

Then open <http://localhost:8000/install.php>. `PHP_CLI_SERVER_WORKERS` matters: the
installer checks URL rewriting by requesting the site itself, which a single-worker dev
server cannot answer while it is busy with the installer. After installing, add
`APP_DEBUG="true"` to `.env` for readable error traces.

## Installation

1. Upload the files and point the document root at `public/` (see Deployment).
2. For MySQL, create an empty database with `utf8mb4` as its character set.
3. Open `https://your-site/install.php` and follow the steps:
   - **Requirements.** Anything required blocks installation. The page asks for the
     install token, which the installer has just written to
     `storage/install-token.txt`; open that file via FTP or your host's file manager
     and paste its contents. This proves you control the server.
   - **Database.** MySQL (preselected) or SQLite. Connection problems are named.
   - **Admin account.** Email and a password of at least 12 characters.
   - **Site.** Name, time zone and the primary language. **The primary language
     cannot be changed later.**
4. The installer writes `.env` and `storage/install.lock`, then deletes itself. If it
   cannot, delete `public/install.php` by hand. Log in at `/admin`.

To reinstall, delete `storage/install.lock` and `.env`, and start from an empty
database.

## Pages

Log in at `/admin` and open **Pages**. A new page can start from a template, which
pre-fills its blocks. Edit the blocks, reorder them (drag, or Move up / Move down),
and press **Save page**; nothing is saved until you do, and leaving with unsaved
changes asks first. Editing and saving also work with JavaScript turned off.

A page is a draft until published; visitors get a 404 for drafts. The page with an
empty address is the home page of its language: `/` for the primary language, `/hr/`
for Croatian. Addresses cannot be language codes such as `de`, even for languages
that are not enabled, nor paths Boxlet uses itself such as `admin`.

### max_input_vars

The editor sends a whole page as one form, and PHP silently drops fields beyond its
`max_input_vars` setting (default 1000, roughly a hundred blocks). Boxlet detects
this and refuses the save with a message rather than saving a page with content
missing. The installer shows the current value. To raise it, set in `php.ini`,
`.user.ini` or your hosting panel:

```ini
max_input_vars = 3000
```

On Apache with mod_php, `php_value max_input_vars 3000` in `.htaccess` also works.

## Tests

Run `php tests/run.php`. It needs no web server; exits non-zero on failure.

Tests run against SQLite always, and against MySQL when a database that exists only
for tests is configured: copy `.env.test.example` to `.env.test` and fill it in, or
set the same variables in the environment. Every table in that database is dropped
on each run, so never point it at a real site. Without it, MySQL tests are skipped.

Static analysis: `composer install` (includes dev tools), then `vendor/bin/phpstan analyse`.

## Deployment

In every case the document root must point at `public/`. `app/`, `config/`,
`storage/` and `vendor/` must not be reachable from the web.

**URL rewriting is required, not optional.** Every request that is not a real file
must reach `public/index.php`. There is no fallback URL mode. The installer checks
this and refuses to continue without it.

### Apache (shared hosting)

Set the document root to `public/`, enable `mod_rewrite` and allow `.htaccess`
overrides. `public/.htaccess` already contains the rules:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</IfModule>
```

If `mod_rewrite` is missing, the site shows a page explaining how to enable it
instead of a bare 404.

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
protection for `app/`, `config/` and `storage/`. On a managed host without access to
the server block, ask the provider to add the `try_files` line.

## License

MIT, see [LICENSE](LICENSE).
