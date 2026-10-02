<?php

/*
 * THE COPY'S ROUTER, for PHP's built-in server and the browser suite only. NOT part of Boxlet
 * (PLAN.md D-174). sync-copy.sh writes it beside the copy as dev-router.php, the name the
 * running server was started with, with __SITE__ replaced by the copy's directory; the
 * built-in server reads its router afresh on every request, so a sync takes effect at once.
 *
 * Why it is needed at all: `php -S host:port -t docroot public/index.php` hands EVERY request
 * to index.php, which is a front controller, not a router, so every stylesheet, script and
 * image would 404.
 *
 * WHY IT ANSWERS A FILE ITSELF. A file on disk is answered as nginx answers it on the
 * development site — an ETag and a Last-Modified made from the file, a 304 to a request that
 * already has it — so a scenario that checks "served from disk, not by PHP" (36-sitemap)
 * checks the same thing on the copy. The built-in server's own file answer carries neither.
 */

$site = '__SITE__';
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = $site . '/public' . rawurldecode($path);

// A PHP file that is there — install.php — is the server's to run, as before.
if ($path !== '/' && is_file($file) && str_ends_with($file, '.php')) {
    return false;
}
if ($path === '/' || !is_file($file)) {
    require $site . '/public/index.php';

    return true;
}

$types = [
    'css' => 'text/css', 'js' => 'text/javascript', 'mjs' => 'text/javascript', 'json' => 'application/json',
    'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
    'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'woff' => 'font/woff',
    'xml' => 'application/xml', 'txt' => 'text/plain; charset=utf-8', 'html' => 'text/html; charset=utf-8',
    'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'zip' => 'application/zip',
];
$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$size = (int) filesize($file);
$modified = (int) filemtime($file);
// nginx's own shape: the modification time and the size, in hexadecimal.
$etag = sprintf('"%x-%x"', $modified, $size);

header('Content-Type: ' . ($types[$extension] ?? (mime_content_type($file) ?: 'application/octet-stream')));
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);

    return true;
}
header('Content-Length: ' . $size);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    readfile($file);
}

return true;
