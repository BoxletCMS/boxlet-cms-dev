<?php

function store($db, Request $request, string $key): void
{
    $ip = ClientIp::of($request, '');
    // ruleid: boxlet-ip-stored
    $db->query('INSERT INTO form_sends (ip, created_at) VALUES (?, ?)', [$ip, gmdate('Y-m-d')]);
    // ruleid: boxlet-ip-stored
    Activity::record($db, 'login', 'failed', null, $_SERVER['REMOTE_ADDR']);
    $hash = hash_hmac('sha256', $ip, $key);
    // ok: boxlet-ip-stored
    $db->query('INSERT INTO form_sends (ip_hash, created_at) VALUES (?, ?)', [$hash, gmdate('Y-m-d')]);
}
