<?php

use App\Core\Settings;
use App\Modules\Design\Design;
use App\Modules\Design\TokenCompiler;

/*
 * TOKENS.CSS FOLLOWS THE CODE (PLAN.md D-189, O-53). A site kept the stylesheet it had until its
 * design was next published, so code put on the server by FTP or git that changed what the
 * tokens derive (D-187's floor under small text) never reached it: the development site served
 * --text-sm 0.812rem under the 0.902rem floor. The code's version is now part of the file's
 * name and of the record, and a page compiles again when it differs. adminSite() is
 * pages_admin_test.php's.
 */

test('the same tokens compiled by other code are another file', function () {
    $dir = sys_get_temp_dir() . '/boxlet-code-' . bin2hex(random_bytes(4));
    $tokens = ['color' => ['text' => '#111111']];
    $compiler = new TokenCompiler();
    $old = $compiler->compile($tokens, $dir, '', 'older code');
    $new = $compiler->compile($tokens, $dir, '', 'newer code');
    assertTrue($old !== $new, "one name for two versions of the code: {$old}");
    assertTrue(preg_match('~^tokens\.[0-9a-f]{12}\.css$~', $new) === 1, 'still a name a page may link');
    assertTrue(!is_file($dir . '/' . $old), 'the older one removed');
    array_map('unlink', glob($dir . '/*') ?: []);
    rmdir($dir);
});

testBothDrivers('a stylesheet compiled by older code is compiled again by the next page, nothing published', function (string $driver) {
    $db = adminSite($driver);
    $dir = sys_get_temp_dir() . '/boxlet-code-' . bin2hex(random_bytes(4));
    $current = Design::publish($db, $dir);
    assertEquals($current, Design::stylesheet($db, $dir), 'the same code: the recorded file, as it was');

    // What the site had before an update: the same design, compiled by other code.
    $older = (new TokenCompiler())->compile(['color' => ['text' => '#111111']], $dir, '', 'older code');
    Settings::set($db, 'tokens_css', $older);
    Settings::set($db, 'tokens_code', 'older code');
    $again = Design::stylesheet($db, $dir);
    assertTrue($again !== $older, 'a new file, not the older one');
    assertEquals($current, $again, 'the one this code compiles');
    assertEquals(Design::code(), Settings::get($db, 'tokens_code'), 'recorded with the code that made it');
    assertTrue(is_file($dir . '/' . $again) && !is_file($dir . '/' . $older), 'on disk, the older gone');
    array_map('unlink', glob($dir . '/*') ?: []);
    rmdir($dir);
});
