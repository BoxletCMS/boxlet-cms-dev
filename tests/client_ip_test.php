<?php

use App\Core\Request;
use App\Core\Response;
use App\Modules\Stats\Tracker;
use App\Support\ClientIp;

// The visitor's own address where the site sits behind a proxy (PLAN.md O-20).

/** A request from $from, saying it was forwarded for $forwarded. */
function ipRequest(string $from, string $forwarded = ''): Request
{
    return new Request('GET', '/', '', [], [], $forwarded === '' ? [] : ['x-forwarded-for' => $forwarded], $from);
}

test('without a list of proxies, the address is the one the server reports', function () {
    assertEquals('203.0.113.9', ClientIp::of(ipRequest('203.0.113.9', '198.51.100.7'), ''), 'a header nobody asked to trust');
    assertEquals('203.0.113.9', ClientIp::of(ipRequest('203.0.113.9'), '   '), 'a list of nothing');
});

test('a proxy that is listed is believed, and one that is not is not', function () {
    $proxies = "10.0.0.7\n173.245.48.0/20";

    assertEquals('198.51.100.7', ClientIp::of(ipRequest('10.0.0.7', '198.51.100.7'), $proxies), 'from the listed proxy');
    assertEquals('198.51.100.7', ClientIp::of(ipRequest('173.245.48.9', '198.51.100.7'), $proxies), 'from a listed range');
    // The same header from someone who is not a proxy is a visitor claiming to be elsewhere.
    assertEquals('203.0.113.9', ClientIp::of(ipRequest('203.0.113.9', '198.51.100.7'), $proxies), 'from anyone else');
    assertEquals('173.245.64.1', ClientIp::of(ipRequest('173.245.64.1', '198.51.100.7'), $proxies), 'from just outside the range');
});

test('through several proxies, the address is the last one that is not ours', function () {
    $proxies = "10.0.0.0/8\n192.0.2.1";

    assertEquals('198.51.100.7', ClientIp::of(ipRequest('10.0.0.7', '198.51.100.7, 10.0.0.3, 10.0.0.5'), $proxies), 'our own machines dropped');
    // A visitor can write anything into the header before it reaches the first proxy; what
    // is believed is the address the proxy chain adds, never what came before it.
    assertEquals('198.51.100.7', ClientIp::of(ipRequest('10.0.0.7', 'not-an-address, 198.51.100.7, 10.0.0.3'), $proxies), 'rubbish before it');
    assertEquals('10.0.0.7', ClientIp::of(ipRequest('10.0.0.7', '10.0.0.3, 10.0.0.5'), $proxies), 'nothing but our own');
});

test('a port, brackets and IPv6 are all read', function () {
    $proxies = '2400:cb00::/32';

    assertEquals('198.51.100.7', ClientIp::of(ipRequest('2400:cb00:1::5', '198.51.100.7:44321'), $proxies), 'an address with a port');
    assertEquals('2001:db8::1', ClientIp::of(ipRequest('2400:cb00:1::5', '[2001:db8::1]:443'), $proxies), 'IPv6 in brackets with a port');
    assertEquals('2001:db8::1', ClientIp::of(ipRequest('2400:cb00:1::5', '2001:db8::1'), $proxies), 'IPv6 plain');
    assertEquals('203.0.113.9', ClientIp::of(ipRequest('203.0.113.9', '2001:db8::1'), '2400:cb00::/33'), 'an IPv6 range that does not hold it');
});

testBothDrivers('the visitor behind a proxy is counted as themselves', function (string $driver) {
    $db = statsSite($driver);
    App\Core\Settings::set($db, 'trusted_proxies', '10.0.0.7');
    $through = fn (string $forwarded) => Tracker::record(
        $db,
        new Request('GET', '/about', '', [], [], ['user-agent' => STATS_CHROME, 'host' => 'example.test', 'x-forwarded-for' => $forwarded], '10.0.0.7'),
        Response::html('x'),
        new DateTimeImmutable('2026-09-19 10:00:00'),
    );

    $through('198.51.100.7');
    $through('198.51.100.7');
    $through('203.0.113.9');

    // Without the list they would be one visitor — the proxy — with three views.
    assertEquals(['views' => 3, 'visitors' => 2], statsTotals($db), 'two people through one proxy');
});
