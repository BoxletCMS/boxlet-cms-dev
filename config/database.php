<?php

$path = (string) env('DB_PATH', 'storage/database.sqlite');

return [
    'driver' => 'sqlite',
    'path' => str_starts_with($path, '/') ? $path : dirname(__DIR__) . '/' . $path,
];
