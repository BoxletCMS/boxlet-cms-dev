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
