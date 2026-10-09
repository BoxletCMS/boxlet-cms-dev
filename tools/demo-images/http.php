<?php

/*
 * EVERY REQUEST THE DEMO-IMAGES RUN MAKES, cached, and spaced to each API's limit. A rerun
 * fetches nothing it has fetched before: the cache is the record of what the sources said —
 * except Pixabay's answers, which its terms keep for 24 hours and no longer.
 *
 * No key is ever written to a log line or a file name: an address is shown with its key
 * replaced, and a cache file is named by a hash.
 *
 *   Pixabay     100 a minute, X-RateLimit-*     one every 0.65 s, and a wait when it says 0 left
 *   Pexels      200 searches an hour            one every 19 s
 *   Openverse   20 a minute, 200 a day, anonymous   one every 3.5 s
 *   The Met     80 a second, its firewall less one every 1 s
 *   AIC         60 a minute                     one every 1.1 s
 *   pictures    from the sources' own servers   one every 0.5 s
 */

const SPACING = [
    'pixabay.com' => 0.65,
    'api.pexels.com' => 19.0,
    'api.openverse.org' => 3.5,
    // Its own limit is 80 a second, but its firewall answered 403 at three a second.
    'collectionapi.metmuseum.org' => 1.0,
    'api.artic.edu' => 1.1,
];

/** Pixabay's pictures (its /get/ addresses), slower than its API: they answered 429 at 0.65 s. */
const PIXABAY_PICTURES = 2.0;

/** The last request to each host, in microtime. */
$GLOBALS['demo_last'] = [];

function demo_wait(string $host, bool $picture = false): void
{
    $gap = $picture && $host === 'pixabay.com' ? PIXABAY_PICTURES : (SPACING[$host] ?? 0.5);
    $last = $GLOBALS['demo_last'][$host] ?? 0.0;
    $left = $gap - (microtime(true) - $last);
    if ($left > 0) {
        usleep((int) ($left * 1_000_000));
    }
    $GLOBALS['demo_last'][$host] = microtime(true);
}

/**
 * A GET, from the cache when it has been made before. Null when it fails; a 429 is waited out
 * once, as Retry-After says, and asked again.
 *
 * @param array<string, string> $headers
 */
function demo_get(string $url, string $cacheFile, array $headers, string $userAgent, int $ttl = 0): ?string
{
    if (is_file($cacheFile) && ($ttl === 0 || filemtime($cacheFile) > time() - $ttl)) {
        return (string) file_get_contents($cacheFile);
    }
    $host = (string) parse_url($url, PHP_URL_HOST);
    $shown = (string) preg_replace('~([?&]key=)[^&]+~', '$1…', $url);
    for ($attempt = 0; $attempt < 2; $attempt++) {
        demo_wait($host, str_contains($url, '/get/'));
        $lines = ['User-Agent: ' . $userAgent];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $context = stream_context_create(['http' => ['timeout' => 120, 'ignore_errors' => true, 'header' => implode("\r\n", $lines)]]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        $retry = 60;
        $left = null;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m) === 1) {
                $status = (int) $m[1];
            } elseif (preg_match('~^(?:retry-after|x-ratelimit-reset):\s*(\d+)~i', $line, $m) === 1) {
                $retry = (int) $m[1];
            } elseif (preg_match('~^x-ratelimit-remaining:\s*(\d+)~i', $line, $m) === 1) {
                $left = (int) $m[1];
            }
        }
        // Pixabay says how many are left this minute: at none, the rest of the minute is waited.
        if ($left === 0) {
            fwrite(STDERR, "  {$host}: none left this minute; waiting {$retry} s\n");
            sleep(min($retry, 120));
        }
        // The Met's firewall says 403 where an API would say 429.
        if (($status === 429 || ($status === 403 && $host === 'collectionapi.metmuseum.org')) && $attempt === 0) {
            // At least a minute: Pixabay's picture server answered 429 with a reset of 0.
            $retry = max(60, $retry);
            fwrite(STDERR, "  {$host} says too many requests; waiting {$retry} s\n");
            sleep(min($retry, 900));
            continue;
        }
        if ($status !== 200 || !is_string($body) || $body === '') {
            fwrite(STDERR, "  {$status} for {$shown}\n");
            // A stale answer is better than none when the source is down.
            if (is_file($cacheFile)) {
                return (string) file_get_contents($cacheFile);
            }

            return null;
        }
        if (!is_dir(dirname($cacheFile))) {
            mkdir(dirname($cacheFile), 0775, true);
        }
        file_put_contents($cacheFile, $body);

        return $body;
    }

    return null;
}

/**
 * A JSON answer, cached by its URL.
 *
 * @param array<string, string> $headers
 * @return array<mixed>|null
 */
function demo_json(string $url, string $cache, array $headers, string $userAgent, int $ttl = 0): ?array
{
    $body = demo_get($url, $cache . '/api/' . sha1($url) . '.json', $headers, $userAgent, $ttl);
    $data = $body === null ? null : json_decode($body, true);

    return is_array($data) ? $data : null;
}
