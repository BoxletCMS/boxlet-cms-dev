<?php

use App\Support\Url;

// Url::page(): the primary locale never has a prefix, every other locale always does.

testBothModes('primary locale has no prefix', function (bool $pretty) {
    Url::configure('', $pretty, 'en');
    assertEquals(expectedUrl('/', $pretty), Url::page('en'), "page('en')");
    assertEquals(expectedUrl('/hello', $pretty), Url::page('en', 'hello'), "page('en', 'hello')");
    assertEquals(expectedUrl('/about/team', $pretty), Url::page('en', 'about/team'), "page('en', 'about/team')");
});

testBothModes('other locales always carry their prefix', function (bool $pretty) {
    Url::configure('', $pretty, 'en');
    assertEquals(expectedUrl('/hr/', $pretty), Url::page('hr'), "page('hr')");
    assertEquals(expectedUrl('/hr/hello', $pretty), Url::page('hr', 'hello'), "page('hr', 'hello')");
    assertEquals(expectedUrl('/de/hello', $pretty), Url::page('de', 'hello'), "page('de', 'hello')");
});

testBothModes('the prefix follows the configured primary, not a fixed code', function (bool $pretty) {
    Url::configure('', $pretty, 'hr');
    assertEquals(expectedUrl('/hello', $pretty), Url::page('hr', 'hello'), "page('hr', 'hello')");
    assertEquals(expectedUrl('/en/hello', $pretty), Url::page('en', 'hello'), "page('en', 'hello')");
});

testBothModes('slug segments are percent-encoded exactly once', function (bool $pretty) {
    Url::configure('', $pretty, 'en');
    assertEquals(expectedUrl('/hr/o%20nama', $pretty), Url::page('hr', 'o nama'), "page('hr', 'o nama')");
});
