<?php

function reads(Request $request): string
{
    // ruleid: boxlet-superglobals
    $a = $_GET['q'] ?? '';
    // ruleid: boxlet-superglobals
    $b = $_POST['name'];
    // ruleid: boxlet-superglobals
    $c = $_COOKIE['boxlet_session'];
    // ok: boxlet-superglobals
    $d = $request->input('name');
    // ok: boxlet-superglobals
    $e = $_SERVER['REQUEST_METHOD'];

    return $a . $b . $c . $d . $e;
}
