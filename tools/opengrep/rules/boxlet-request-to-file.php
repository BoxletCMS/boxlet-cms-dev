<?php

final class FileController
{
    public function bad(Request $request, string $locale, array $params): Response
    {
        $name = $request->input('name');
        // ruleid: boxlet-request-to-file
        unlink('/storage/backups/' . $name);
        // ruleid: boxlet-request-to-file
        $body = file_get_contents('/storage/' . $params['file']);
        // ruleid: boxlet-request-to-file
        include __DIR__ . '/views/' . $request->query['view'];

        return Response::html($body);
    }

    public function good(Request $request, string $locale, array $params): Response
    {
        $id = (int) $params['id'];
        // ok: boxlet-request-to-file
        unlink('/storage/uploads/' . $id . '.jpg');
        // ok: boxlet-request-to-file
        $body = file_get_contents('/storage/' . basename($request->input('name')));
        // ok: boxlet-request-to-file
        unlink('/storage/fixed.lock');

        return Response::html($body);
    }
}

function upload(Request $request): string
{
    // ok: boxlet-request-to-file
    return (string) file_get_contents($request->files['file']['tmp_name']);
}
