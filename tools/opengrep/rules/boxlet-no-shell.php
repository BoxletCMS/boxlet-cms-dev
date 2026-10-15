<?php

function runs(string $file, string $data): mixed
{
    // ruleid: boxlet-no-shell
    exec('convert ' . $file);
    // ruleid: boxlet-no-shell
    $out = shell_exec('ls');
    // ruleid: boxlet-no-shell
    eval($data);
    // ruleid: boxlet-no-shell
    $value = unserialize($data);
    // ok: boxlet-no-shell
    $decoded = json_decode($data, true);
    // ok: boxlet-no-shell
    $result = curl_exec(curl_init('https://example.com'));

    return [$out, $value, $decoded, $result];
}
