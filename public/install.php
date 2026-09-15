<?php

use App\Core\ErrorHandler;
use App\Core\Request;
use App\Core\RewriteCheck;
use App\Core\Session;
use App\Modules\Design\TokenCompiler;
use App\Modules\Install\InstallController;
use App\Support\Url;

// Boxlet installer. Refuses to run once storage/install.lock exists, and tries to
// delete itself when it finishes (SPEC §6). The installer logic is in app/Modules/Install.

$root = dirname(__DIR__);

if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(500);
    echo 'Dependencies are missing. Upload the release ZIP, which includes vendor/.';
    exit;
}

require $root . '/vendor/autoload.php';

ErrorHandler::register(false);

$storage = $root . '/storage';
$request = Request::fromGlobals();
Url::configure($request->basePath, '');

$tokensFile = $root . '/public/cache/tokens.css';
if (!is_file($tokensFile) && is_writable(dirname($tokensFile))) {
    (new TokenCompiler())->compile(require $root . '/config/tokens.php', $tokensFile);
}

// When storage/ is not writable, fall back to PHP's session path so the requirements
// page can still say so.
$sessionPath = is_dir($storage) && is_writable($storage) ? $storage . '/sessions' : '';
$session = Session::start($sessionPath, $request->https);

$baseUrl = Url::serverBase(
    $request->https,
    (string) ($_SERVER['SERVER_NAME'] ?? 'localhost'),
    (int) ($_SERVER['SERVER_PORT'] ?? 0),
);

$installer = new InstallController(
    $root,
    $storage,
    $root . '/.env',
    __FILE__,
    $session,
    static fn (): bool => RewriteCheck::works($baseUrl),
);

$installer->handle($request)->send();
