<?php

use App\Support\Url;

// Url::page(): the primary locale never has a prefix, every other locale always does.

test('primary locale has no prefix', function () {
    Url::configure('', 'en');
    assertEquals('/', Url::page('en'), "page('en')");
    assertEquals('/hello', Url::page('en', 'hello'), "page('en', 'hello')");
    assertEquals('/about/team', Url::page('en', 'about/team'), "page('en', 'about/team')");
});

test('other locales always carry their prefix', function () {
    Url::configure('', 'en');
    assertEquals('/hr/', Url::page('hr'), "page('hr')");
    assertEquals('/hr/hello', Url::page('hr', 'hello'), "page('hr', 'hello')");
    assertEquals('/de/hello', Url::page('de', 'hello'), "page('de', 'hello')");
});

test('the prefix follows the configured primary, not a fixed code', function () {
    Url::configure('', 'hr');
    assertEquals('/hello', Url::page('hr', 'hello'), "page('hr', 'hello')");
    assertEquals('/en/hello', Url::page('en', 'hello'), "page('en', 'hello')");
});

test('slug segments are percent-encoded exactly once', function () {
    Url::configure('', 'en');
    assertEquals('/hr/o%20nama', Url::page('hr', 'o nama'), "page('hr', 'o nama')");
});
