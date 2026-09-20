<?php

use App\Modules\Stats\Geo;
use App\Modules\Stats\GeoDownload;
use App\Modules\Stats\Place;

// Where visitors are, past the country (PLAN.md D-055). writeMmdb() comes from
// stats_test.php, and writes a database in DB-IP's own shape.

/** The networks the city fixture knows, with the oddities DB-IP's real data has. */
const PLACE_NETWORKS = [
    // Two Zagreb addresses that DB-IP puts in two differently named regions. Measured on
    // 161.53.1.1 and 31.147.200.1 in the real file; this is what it costs us.
    '198.51.100.0/25' => ['country' => 'HR', 'region' => 'City of Zagreb', 'city' => 'Zagreb', 'lat' => 45.7924, 'lon' => 15.9694],
    '198.51.100.128/25' => ['country' => 'HR', 'region' => 'Zagreb', 'city' => 'Zagreb (Trešnjevka)', 'lat' => 45.815, 'lon' => 15.98194],
    '203.0.113.0/24' => ['country' => 'AT', 'region' => 'Vienna', 'city' => 'Vienna (Leopoldstadt)', 'lat' => 48.2085, 'lon' => 16.3721],
    // A country database's row: no region, no city, no coordinates.
    '8.8.8.0/24' => 'US',
];

test('a place is read at the level asked for, and no further', function () {
    $record = [
        'country' => ['iso_code' => 'hr'],
        'subdivisions' => [['names' => ['en' => 'County of Osijek-Baranja']]],
        'city' => ['names' => ['en' => 'Osijek (Retfala)']],
        'location' => ['latitude' => 45.55111, 'longitude' => 18.69389],
    ];

    $country = Place::of($record, 'country');
    assertEquals('HR', $country->country, 'the code, upper case');
    assertEquals('', $country->city, 'no city at the country level');
    assertEquals(null, $country->latitude, 'no coordinate at the country level');

    $region = Place::of($record, 'region');
    assertEquals('Osijek-Baranja', $region->region, 'the region, without its administrative words');
    assertEquals('', $region->city, 'no city at the region level');

    $city = Place::of($record, 'city');
    assertEquals('Osijek', $city->city, 'the city, without the district in brackets');
    // Two decimal places: a marker on the city, not a marker on a street.
    assertEquals(45.55, $city->latitude, 'the latitude, rounded');
    assertEquals(18.69, $city->longitude, 'the longitude, rounded');
    assertTrue($city->isKnown(), 'a place with a country is known');
});

test('the same place spelled two ways becomes one place', function () {
    // The defect this rule exists for: DB-IP's own data has both spellings, so without
    // this the busiest city in a Croatian site's statistics arrives as two rows.
    $of = static fn (string $region, string $city): string => Place::of([
        'country' => ['iso_code' => 'HR'],
        'subdivisions' => [['names' => ['en' => $region]]],
        'city' => ['names' => ['en' => $city]],
    ], 'city')->region . '/' . Place::of([
        'country' => ['iso_code' => 'HR'],
        'subdivisions' => [['names' => ['en' => $region]]],
        'city' => ['names' => ['en' => $city]],
    ], 'city')->city;

    assertEquals($of('Zagreb', 'Zagreb'), $of('City of Zagreb', 'Zagreb (Trešnjevka)'), 'one row, not two');
    assertEquals('Berlin/Berlin', $of('State of Berlin', 'Berlin (Bezirk Tempelhof-Schöneberg)'), 'a state and a district');
    // A name that only looks administrative keeps every word of itself.
    assertEquals('New York/New York', $of('New York', 'New York'), 'a name with no administrative word');
    assertEquals('Northern Cape/Cape Town', $of('Northern Cape', 'Cape Town'), 'a name ending in a word we never strip');
});

test('a record that says nothing gives a place that is not known', function () {
    foreach ([null, 'nonsense', [], ['country' => []], ['country' => ['iso_code' => 'HRV']]] as $record) {
        $place = Place::of($record, 'city');
        assertTrue(!$place->isKnown(), 'a place from ' . json_encode($record));
        assertEquals('', $place->country, 'the country');
        assertEquals('', $place->city, 'the city');
    }
});

test('a city database answers at every level, and a country one answers what it has', function () {
    $storage = tmpPath('storage');
    writeMmdb(tmpPath('city.mmdb'), PLACE_NETWORKS, 6, 24, 'Test-City');
    Geo::install($storage, tmpPath('city.mmdb'));

    assertEquals('HR', Geo::place($storage, '198.51.100.9')->country, 'the country, by default');
    assertEquals('', Geo::place($storage, '198.51.100.9')->city, 'the city is not read unless asked for');
    assertEquals('Zagreb', Geo::place($storage, '198.51.100.9', 'city')->city, 'the city when asked for');
    assertEquals('Zagreb', Geo::place($storage, '198.51.100.200', 'city')->city, 'the other Zagreb');
    assertEquals('Vienna', Geo::place($storage, '203.0.113.7', 'city')->city, 'a city with a district');
    assertEquals(48.21, Geo::place($storage, '203.0.113.7', 'city')->latitude, 'its latitude');

    // The status says what the file can do. Here it can only be read from the name: the
    // address every database knows, 8.8.8.8, is in this fixture with a country alone.
    $status = Geo::status($storage);
    assertTrue($status !== null && $status['cities'], 'a city database says it knows cities');

    // 8.8.8.8 is in this fixture with a country and nothing else, as a country database has it.
    assertEquals('US', Geo::place($storage, '8.8.8.8', 'city')->country, 'a row with only a country');
    assertEquals('', Geo::place($storage, '8.8.8.8', 'city')->city, 'and no city to give');

    // And here it can only be read from the file, since the name says nothing: a database
    // called Test-Country whose known row carries a city.
    writeMmdb(tmpPath('named-wrong.mmdb'), ['8.8.8.0/24' => ['country' => 'US', 'city' => 'Mountain View']]);
    Geo::install($storage, tmpPath('named-wrong.mmdb'));
    $status = Geo::status($storage);
    assertTrue($status !== null && $status['cities'], 'a database whose name says nothing, but whose rows have cities');

    writeMmdb(tmpPath('country.mmdb'), ['8.8.8.0/24' => 'US', '198.51.100.0/24' => 'HR']);
    Geo::install($storage, tmpPath('country.mmdb'));
    $status = Geo::status($storage);
    assertTrue($status !== null && !$status['cities'], 'a country database says it does not');
    assertEquals('', Geo::place($storage, '198.51.100.9', 'city')->city, 'no city to be had from it');
});

/**
 * A fetcher that answers out of $body, as a server doing ranges would. $changed makes it
 * answer with the whole file instead, which is what an ETag that no longer matches looks
 * like.
 */
function fakeFetch(string $body, bool $changed = false): Closure
{
    return static function (string $url, int $from, int $to, string $etag, string $target) use ($body, $changed): array {
        $whole = $changed && $from > 0;
        $piece = $whole ? $body : substr($body, $from, $to - $from + 1);
        file_put_contents($target, $piece);

        return [
            'bytes' => strlen($piece),
            'total' => strlen($body),
            'etag' => '"the-file"',
            'whole' => $whole,
            'error' => '',
        ];
    };
}

test('a download carries on from where it stopped, and finishes', function () {
    $storage = tmpPath('storage');
    GeoDownload::cancel($storage);
    writeMmdb(tmpPath('whole.mmdb'), PLACE_NETWORKS);
    $body = (string) gzencode((string) file_get_contents(tmpPath('whole.mmdb')));
    $fetch = fakeFetch($body);

    assertEquals(null, GeoDownload::progress($storage), 'nothing before it starts');
    GeoDownload::start($storage, new DateTimeImmutable('2026-09-20'), 'https://example.test/%s.gz', $fetch);
    assertEquals(['done' => 0, 'total' => strlen($body)], GeoDownload::progress($storage), 'after the start');

    // Pieces the size of the real ones would make this a one-step download, so the file is
    // read in slices of a few bytes instead: what is being tested is the carrying on.
    $small = static fn (int $size): Closure => static function (string $url, int $from, int $to, string $etag, string $target) use ($fetch, $size): array {
        return $fetch($url, $from, min($to, $from + $size - 1), $etag, $target);
    };

    $steps = 0;
    do {
        $progress = GeoDownload::step($storage, $small(64));
        $steps++;
        assertTrue($steps < 200, 'the download is going round in circles');
    } while (!$progress['finished']);

    assertTrue($steps > 3, "it took {$steps} pieces, so more than one was needed");
    assertEquals(null, GeoDownload::progress($storage), 'the download is forgotten once it is done');
    assertEquals('HR', Geo::place($storage, '198.51.100.9')->country, 'the database it fetched is in use');
});

test('a file that changes under a download starts the download again', function () {
    $storage = tmpPath('storage');
    GeoDownload::cancel($storage);
    writeMmdb(tmpPath('whole.mmdb'), PLACE_NETWORKS);
    $body = (string) gzencode((string) file_get_contents(tmpPath('whole.mmdb')));

    GeoDownload::start($storage, new DateTimeImmutable('2026-09-20'), 'https://example.test/%s.gz', fakeFetch($body));
    $after = GeoDownload::step($storage, static function (string $u, int $from, int $to, string $e, string $t) use ($body): array {
        return fakeFetch($body)($u, $from, min($to, $from + 63), $e, $t);
    });
    assertEquals(64, $after['done'], 'one piece in');

    // Now the server answers with the whole file rather than the range: a different file.
    $restarted = GeoDownload::step($storage, fakeFetch($body, true));
    assertEquals(0, $restarted['done'], 'what was fetched is thrown away');
    assertTrue(!$restarted['finished'], 'and it is not treated as finished');
});

test('carrying on a download nobody started says so', function () {
    $storage = tmpPath('storage');
    GeoDownload::cancel($storage);
    assertThrows(fn () => GeoDownload::step($storage), t('stats.geo_download_none'));
});

/**
 * A site with the city database in place, counting as much of a location as $level asks.
 * statsSite() comes from stats_test.php.
 */
function placeSite(string $driver, string $level): App\Core\Db
{
    $db = statsSite($driver);
    App\Core\Settings::set($db, 'stats_location', $level);
    writeMmdb(tmpPath('place.mmdb'), PLACE_NETWORKS, 6, 24, 'Test-City');
    Geo::install(tmpPath('storage'), tmpPath('place.mmdb'));

    return $db;
}

/**
 * One view from $ip, counted with the location database in reach.
 *
 * A test that renders the SCREEN passes a time of its own: the screen's "today" is the real
 * one, and a view counted on a fixed day in September is not in it. The first version of
 * these tests asked for period=today and found the word "Zagreb" — in "Europe/Zagreb" in the
 * strip above the content. A passing test about nothing.
 */
function placeView(App\Core\Db $db, string $ip, string $path = '/about', string $at = '2026-09-19 10:00:00'): void
{
    App\Modules\Stats\Tracker::record(
        $db,
        new App\Core\Request('GET', $path, '', [], [], ['user-agent' => STATS_CHROME, 'host' => 'example.test'], $ip),
        App\Core\Response::html('<p>A page</p>'),
        new DateTimeImmutable($at, new DateTimeZone('UTC')),
        tmpPath('storage'),
    );
}

/** @return array<int, array<string, mixed>> the places counted, most views first */
function placeRows(App\Core\Db $db): array
{
    return $db->all('SELECT country, region, city, latitude, longitude, views, visitors FROM stats_places ORDER BY views DESC, visitors DESC, city');
}

testBothDrivers('a view is counted where it came from, as far as the owner asked', function (string $driver) {
    $db = placeSite($driver, 'city');
    placeView($db, '198.51.100.9');
    placeView($db, '198.51.100.9', '/services');
    placeView($db, '203.0.113.7');

    $rows = placeRows($db);
    assertEquals(2, count($rows), 'one row per place');
    assertEquals(['HR', 'Zagreb', 'Zagreb'], [$rows[0]['country'], $rows[0]['region'], $rows[0]['city']], 'the busiest place');
    assertEquals([2, 1], [(int) $rows[0]['views'], (int) $rows[0]['visitors']], 'two views, one visitor');
    assertEquals([45.79, 15.97], [(float) $rows[0]['latitude'], (float) $rows[0]['longitude']], 'the city\'s coordinates');
    assertEquals('Vienna', $rows[1]['city'], 'the other place');

    // The path is not in this table and is not going to be: the row is the same row
    // whichever page was read.
    assertEquals(0, count(array_filter(
        $db->all('SELECT * FROM stats_places'),
        static fn (array $row): bool => array_key_exists('path', $row),
    )), 'a path column in stats_places');
});

testBothDrivers('the level decides how much of a place is kept', function (string $driver) {
    $db = placeSite($driver, 'country');
    placeView($db, '198.51.100.9');
    $rows = placeRows($db);
    assertEquals(['HR', '', ''], [$rows[0]['country'], $rows[0]['region'], $rows[0]['city']], 'the country alone');
    assertEquals(null, $rows[0]['latitude'], 'and no coordinate');

    App\Core\Settings::set($db, 'stats_location', 'region');
    placeView($db, '198.51.100.9', '/about', '2026-09-20 10:00:00');
    $rows = $db->all("SELECT region, city FROM stats_places WHERE day = '2026-09-20'");
    assertEquals(['Zagreb', ''], [$rows[0]['region'], $rows[0]['city']], 'the region, and no city');

    // Turning it up does not rewrite what was counted before: the days keep what they had.
    assertEquals(1, count($db->all("SELECT 1 FROM stats_places WHERE day = '2026-09-19' AND region = ''")), 'the day before is untouched');
});

testBothDrivers('a city too small to name is counted with the others', function (string $driver) {
    $db = placeSite($driver, 'city');
    // Six visitors in Zagreb, one in Vienna. Five is the floor, so Vienna is not named.
    foreach (range(1, 6) as $n) {
        placeView($db, '198.51.100.' . $n);
    }
    placeView($db, '203.0.113.7');

    $cities = (new App\Modules\Stats\PlaceQuery($db))->top('city', statsFilter(['period' => 'today']), 10, false);
    $named = array_column($cities, 'value');
    assertTrue(in_array('Zagreb', $named, true), 'the city with six visitors is named');
    assertTrue(!in_array('Vienna', $named, true), 'the city with one is not');
    assertTrue(in_array(App\Modules\Stats\StatsQuery::OTHER, $named, true), 'and is counted in the gathered row');
    assertEquals(t('stats.other_small', ['count' => '5']), App\Modules\Stats\StatsView::label('cities', App\Modules\Stats\StatsQuery::OTHER), 'the row says the floor it stands for');
});

testBothDrivers('the screen shows the places, and says when it cannot', function (string $driver) {
    $db = placeSite($driver, 'city');
    $now = gmdate('Y-m-d H:i:s');
    foreach (range(1, 6) as $n) {
        placeView($db, '198.51.100.' . $n, '/about', $now);
    }

    $screen = dispatch('/admin/statistics?period=30d')->body;
    assertContains(e(t('stats.table.cities')), $screen, 'the Cities table');
    // In the table, not anywhere on the page: the site's own time zone is Europe/Zagreb.
    assertContains('<span>Zagreb</span>', $screen, 'the city itself, as a row');
    assertContains(e(t('stats.places_note', ['count' => '5'])), $screen, 'the note about how exact this is');

    // Narrowed to a page, the places cannot be shown at all: they are counted without it.
    $narrowed = dispatch('/admin/statistics?period=30d&path=' . rawurlencode('/about'))->body;
    assertTrue(!str_contains($narrowed, e(t('stats.table.cities'))), 'the Cities table while narrowed to a page');
    assertContains(e(t('stats.places_narrowed')), $narrowed, 'and the reason it is not there');

    // Counting countries only: no place tables, and a line pointing at the setting, since
    // this site has a database that could do more.
    App\Core\Settings::set($db, 'stats_location', 'country');
    $countries = dispatch('/admin/statistics?period=30d')->body;
    assertTrue(!str_contains($countries, e(t('stats.table.regions'))), 'the Regions table while counting countries');
    assertContains(e(t('stats.places_off')), $countries, 'the line about the setting');
});

/** A small map in the shape the build writes: a projection on the svg, a box per country. */
function placeMap(): string
{
    $file = tmpPath('map.svg');
    file_put_contents($file, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 388.9"'
        . ' data-top-lat="84" data-bottom-lat="-56" role="img">'
        . '<path id="c-HR" data-name="Croatia" data-box="537.9 104.2 16 11.1" d="M537.9 104.2L553.9 115.3Z"/>'
        . '<path id="c-AT" data-name="Austria" data-box="520 95 20 8" d="M520 95L540 103Z"/></svg>');

    return $file;
}

/**
 * The first city dot in a map, as numbers. Zero for anything missing, so a map without a
 * dot fails the assertion that asked for one rather than the line that reads it.
 *
 * @return array{x: float, y: float, r: float}
 */
function placeDot(string $svg): array
{
    preg_match('~<circle class="map-city" cx="([\d.]+)" cy="([\d.]+)" r="([\d.]+)"~', $svg, $found);

    return ['x' => (float) ($found[1] ?? 0), 'y' => (float) ($found[2] ?? 0), 'r' => (float) ($found[3] ?? 0)];
}

test('the map marks the cities visitors came from, and can be cut to one country', function () {
    $cities = [
        ['city' => 'Zagreb', 'country' => 'HR', 'visitors' => 20, 'latitude' => 45.79, 'longitude' => 15.97],
        ['city' => 'Split', 'country' => 'HR', 'visitors' => 5, 'latitude' => 43.51, 'longitude' => 16.44],
    ];
    $link = static fn (string $code): string => '/admin/statistics?country=' . $code;

    // The whole world carries no dots: cities that are an hour apart land on top of each
    // other there, and the shading is what answers "which countries" anyway.
    $world = App\Modules\Stats\Map::draw(placeMap(), ['HR' => 25], $link, $cities);
    assertContains('viewBox="0 0 1000 388.9"', $world, 'the whole world');
    assertEquals(0, preg_match_all('~<circle class="map-city"~', $world), 'dots on the world');

    $cut = App\Modules\Stats\Map::draw(placeMap(), ['HR' => 25], $link, $cities, 'HR');
    assertEquals(2, preg_match_all('~<circle class="map-city"~', $cut), 'a dot for each city, where they can be told apart');
    assertContains('<title>Zagreb · ' . e(t('stats.map_visitors', ['count' => '20'])) . '</title>', $cut, 'what a dot says');

    // Zagreb is at 15.97E, 45.79N. On a map 1000 wide, that is (15.97 + 180) / 360 * 1000
    // across and (84 - 45.79) / 360 * 1000 down — the projection the map was built with,
    // and the same numbers whether the map is cut or whole.
    $dot = placeDot($cut);
    assertEquals(544.4, $dot['x'], 'where the dot is across');
    assertEquals(106.1, $dot['y'], 'and down');

    // The busiest city's dot is the biggest.
    preg_match_all('~ r="([\d.]+)"~', $cut, $sizes);
    assertTrue((float) ($sizes[1][0] ?? 0) > (float) ($sizes[1][1] ?? 0), 'the city with more visitors has the bigger dot');
    // Not the country's own box, which is 16 units wide: the map goes no closer in than
    // eight times, because the shapes Boxlet ships are 1:110m and any closer is a cartoon.
    // The frame keeps the world's own proportions, so the panel has no empty half.
    assertContains('viewBox="483.4 85.4 125 48.6"', $cut, 'cut to the country, as close as the map allows');
    // A dot shrinks with the zoom, so it is the size on the screen that it would have been
    // on the world: eight times in means an eighth of the radius.
    assertTrue($dot['r'] < 7.0 / 7, "the dot's radius is {$dot['r']}, which is not scaled to the cut");

    $unknown = App\Modules\Stats\Map::draw(placeMap(), ['HR' => 25], $link, $cities, 'ZZ');
    assertContains('viewBox="0 0 1000 388.9"', $unknown, 'a country the map does not have leaves the world alone');
});

testBothDrivers('the map opens on the one country nearly everybody comes from', function (string $driver) {
    $db = placeSite($driver, 'city');
    // The screen draws the map from the file in its own public directory, which a test run
    // has to itself (PUBLIC_PATH in fixtures.php), so the small map goes there.
    $vendor = tmpPath('public-root') . '/assets/vendor';
    if (!is_dir($vendor)) {
        mkdir($vendor, 0700, true);
    }
    copy(placeMap(), $vendor . '/world-map.svg');
    // Nine visitors from Croatia and one from Austria: over the 70% the map opens on.
    $now = gmdate('Y-m-d H:i:s');
    foreach (range(1, 9) as $n) {
        placeView($db, '198.51.100.' . $n, '/about', $now);
    }
    placeView($db, '203.0.113.7', '/about', $now);

    $screen = dispatch('/admin/statistics?period=30d')->body;
    assertContains(e(t('stats.map_world')), $screen, 'the way back to the whole world');
    assertTrue(!str_contains($screen, 'viewBox="0 0 1000'), 'the map is cut to the country');

    $whole = dispatch('/admin/statistics?period=30d&map=world')->body;
    assertContains('viewBox="0 0 1000', $whole, 'and the address can ask for the world');
    assertContains(e(t('stats.map_one', ['country' => 'HR'])), $whole, 'with the way back to the country');
});

testBothDrivers('the city is forgotten before the rest of a place is', function (string $driver) {
    $db = placeSite($driver, 'city');
    // Two cities in two countries, four months ago.
    $old = (new DateTimeImmutable('-4 months'))->format('Y-m-d H:i:s');
    placeView($db, '198.51.100.9', '/about', $old);
    placeView($db, '198.51.100.10', '/about', $old);
    placeView($db, '203.0.113.7', '/about', $old);
    assertEquals(2, count($db->all("SELECT 1 FROM stats_places WHERE city <> ''")), 'the cities are there to begin with');

    // The first view of a new day does the day's housekeeping; there is no cron (D-051),
    // and three months is the default for how long a place keeps its city.
    placeView($db, '198.51.100.11', '/about', gmdate('Y-m-d H:i:s'));

    $cities = $db->all("SELECT country, city FROM stats_places WHERE city <> '' ORDER BY city");
    assertEquals(1, count($cities), 'only the city inside the window is left');
    assertEquals('Zagreb', $cities[0]['city'], 'today\'s city, which is young enough to keep');

    // The counts survive the collapse: three visitors four months ago, in two countries.
    $collapsed = $db->all("SELECT country, region, views, visitors, latitude FROM stats_places WHERE city = '' ORDER BY country");
    assertEquals(2, count($collapsed), 'one row per region, the city dropped');
    assertEquals(['AT', 'Vienna', 1, 1], [$collapsed[0]['country'], $collapsed[0]['region'], (int) $collapsed[0]['views'], (int) $collapsed[0]['visitors']], 'Austria, without its city');
    assertEquals(['HR', 'Zagreb', 2, 2], [$collapsed[1]['country'], $collapsed[1]['region'], (int) $collapsed[1]['views'], (int) $collapsed[1]['visitors']], 'Croatia, with both its visitors kept');
    assertEquals(null, $collapsed[0]['latitude'], 'and no coordinate to put on a map');
});

testBothDrivers('a site that asks to keep the city keeps it', function (string $driver) {
    $db = placeSite($driver, 'city');
    App\Core\Settings::set($db, 'stats_city_months', 0);
    placeView($db, '198.51.100.9', '/about', (new DateTimeImmutable('-4 months'))->format('Y-m-d H:i:s'));
    placeView($db, '198.51.100.12', '/about', gmdate('Y-m-d H:i:s'));

    assertEquals(2, count($db->all("SELECT 1 FROM stats_places WHERE city <> ''")), 'both days keep their city');
});
