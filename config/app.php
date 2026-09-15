<?php

$storage = (string) env('STORAGE_PATH', 'storage');

return [
    'name' => 'Boxlet',
    'debug' => filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
    // Secret for hashing IPs and emails in login_attempts. Written by the installer.
    'key' => (string) env('APP_KEY', ''),
    // Relative paths resolve from the project root.
    'storage_path' => str_starts_with($storage, '/') ? $storage : dirname(__DIR__) . '/' . $storage,
];
