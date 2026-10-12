<?php

use App\Core\Session;

// THE MAPS' DOWNLOAD, SAID WHERE ITS BUTTONS ARE (PLAN.md D-237, the owner): the settings'
// download steps answer the panel's script in JSON, how far and in words; without the script
// they come back to the settings at the maps' part, and say what they did there, not in the bar
// at the top. Both databases come the same way. DB-IP is never asked: the container's fetcher
// answers from a file made here (statsSite, writeMmdb and fakeFetch are in stats_test and
// places_test).

/** The panel's buttons pressed as its script presses them, or as a form does. */
function geoPress(string $path, bool $json, ?Closure $fetch = null): App\Core\Response
{
    if ($json) {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
    }
    try {
        return dispatch($path, null, 'POST', ['_csrf' => (new Session())->csrfToken()], '203.0.113.10', $fetch === null ? null : static function (App\Core\Container $c) use ($fetch): void {
            $c->set('geo_fetch', static fn () => $fetch);
        });
    } finally {
        unset($_SERVER['HTTP_ACCEPT']);
    }
}

/** A small gzipped location database, as DB-IP sends one. */
function smallDatabase(): string
{
    writeMmdb(tmpPath('panel.mmdb'), STATS_NETWORKS);

    return (string) gzencode((string) file_get_contents(tmpPath('panel.mmdb')));
}

testBothDrivers('the panel\'s script is answered in JSON: how far, in words, and what is in use at the end (D-237)', function (string $driver) {
    statsSite($driver);
    geoStorage();
    $fetch = fakeFetch(smallDatabase());

    $started = geoPress('/admin/settings/statistics/countries', true, $fetch);
    assertEquals(200, $started->status, 'the start');
    $state = json_decode($started->body, true);
    assertEquals('downloading', $state['phase'] ?? null, 'a download under way');
    assertEquals('country', $state['kind'] ?? null, 'the country database, by the same road as the city one');
    assertTrue(($state['total'] ?? 0) > 0 && ($state['done'] ?? -1) === 0, 'its size known, nothing yet fetched: ' . $started->body);
    assertContains('Fetching the country database', (string) ($state['message'] ?? ''), 'in words');

    $step = json_decode(geoPress('/admin/settings/statistics/cities/step', true, $fetch)->body, true);
    assertEquals('installed', $step['phase'] ?? null, 'one piece for a small file, and it is in use');
    assertContains('Location database in use', (string) ($step['inUse'] ?? ''), 'the panel\'s sentence for it');
    assertContains('It knows countries', (string) ($step['inUse'] ?? ''), 'and which');
    assertEquals(t('stats.geo_installed'), $step['message'] ?? null, 'what it means, for a country database');
    assertEquals('HR', App\Modules\Stats\Geo::place((string) TestSite::$env['STORAGE_PATH'], '198.51.100.9')->country, 'the database is in use');
});

testBothDrivers('a download that fails says why, in JSON for the script and beside the buttons without it (D-237)', function (string $driver) {
    statsSite($driver);
    geoStorage();
    $nothing = static fn (string $url, int $from, int $to, string $etag, string $target): array => ['bytes' => 0, 'total' => 0, 'etag' => '', 'whole' => false, 'error' => 'HTTP 404'];

    $json = geoPress('/admin/settings/statistics/cities', true, $nothing);
    assertEquals(422, $json->status, 'refused');
    assertContains('HTTP 404', (string) (json_decode($json->body, true)['message'] ?? ''), 'why');

    // No script: back at the maps' part, and the words are there, not in the bar at the top.
    $form = geoPress('/admin/settings/statistics/cities', false, $nothing);
    assertEquals('/admin/settings#geo', $form->headers['Location'] ?? null, 'back at the maps');
    $page = dispatch('/admin/settings')->body;
    $panel = substr($page, (int) strpos($page, 'id="geo"'));
    assertContains('data-geo-notice>', $panel, 'said in the maps\' part');
    assertContains('HTTP 404', substr($panel, 0, 4000), 'with the reason');
    assertTrue(!str_contains(substr($page, 0, (int) strpos($page, 'id="geo"')), 'HTTP 404'), 'said at the top as well');
});

testBothDrivers('opened part-way down, the panel shows how far it is and carries on by itself (D-237)', function (string $driver) {
    statsSite($driver);
    geoStorage();
    $body = str_repeat('x', App\Modules\Stats\GeoDownload::CHUNK + 10);
    geoPress('/admin/settings/statistics/cities', true, fakeFetch($body));
    geoPress('/admin/settings/statistics/cities/step', true, fakeFetch($body));

    $page = dispatch('/admin/settings')->body;
    assertContains('data-geo-running', $page, 'the script told to carry on');
    assertContains('value="' . App\Modules\Stats\GeoDownload::CHUNK . '"', $page, 'the bar where it got to');
    assertContains('Fetching the city database', $page, 'in words');
    assertContains('assets/geo-download.js', $page, 'the script on the page');
    geoPress('/admin/settings/statistics/cities/cancel', true);
});
