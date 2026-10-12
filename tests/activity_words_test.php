<?php

// EVERY ENTRY OF THE ACTIVITY LOG HAS ITS WORDS (D-226): Overview and the full log show an entry
// by the key activity.{area}.{action}, and one without words showed the key itself
// ("activity.page.discarded", seen on the pictures for boxletcms.org). Every Activity::record in
// the code, its area and action written out or chosen between two, is looked for in lang/en.
// So is its area's name, activity.kind.{area}, the log's first column: three areas had none
// and showed ACTIVITY.KIND.REDIRECT (D-227).

test('every activity the code records has its words in lang/en', function () {
    $root = dirname(__DIR__);
    $words = require $root . '/lang/en/activity.php';
    $missing = [];
    $found = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!str_ends_with((string) $file, '.php')) {
            continue;
        }
        $source = (string) file_get_contents((string) $file);
        // Activity::record($db, 'page', 'published', …) and Activity::record($db, 'settings', $on ? 'cache_on' : 'cache_off', …)
        preg_match_all("~Activity::record\\([^,]+,\\s*'([a-z_]+)',\\s*(?:'([a-z_]+)'|\\\$[a-z]+\\s*\\?\\s*'([a-z_]+)'\\s*:\\s*'([a-z_]+)')~", $source, $calls, PREG_SET_ORDER);
        foreach ($calls as $call) {
            $found++;
            if (!isset($words["activity.kind.{$call[1]}"])) {
                $missing[] = "activity.kind.{$call[1]} (" . basename((string) $file) . ')';
            }
            foreach (array_filter([$call[2] ?? '', $call[3] ?? '', $call[4] ?? '']) as $action) {
                $found++;
                $key = "activity.{$call[1]}.{$action}";
                if (!isset($words[$key])) {
                    $missing[] = $key . ' (' . basename((string) $file) . ')';
                }
            }
        }
    }
    assertTrue($found > 50, "only {$found} recorded activities found: the pattern no longer reads the code");
    assertEquals([], array_values(array_unique($missing)), 'activities with no words');
});
