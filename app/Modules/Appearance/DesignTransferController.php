<?php

namespace App\Modules\Appearance;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Design\DesignVocabulary;

/**
 * Designs as files, in and out of the Appearance screen (PLAN.md D-152).
 *
 * Every address here is without an extension, though what it answers is JSON: managed nginx
 * answers an address ending in .json from the disk and never asks PHP (routing_test.php), so
 * the file's name travels in Content-Disposition instead.
 */
final class DesignTransferController
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * The JSON Schema of a design set, generated from the code that validates one. For a
     * person writing a set by hand, an editor that checks one, and later a model asked for
     * one; designs/design-set.schema.json is the same text, kept in the repository.
     *
     * @param array<string, string> $params
     */
    public function schema(Request $request, string $locale, array $params): Response
    {
        return new Response(DesignVocabulary::schemaJson($this->container->get('blocks')), 200, [
            'Content-Type' => 'application/schema+json; charset=utf-8',
            'Content-Disposition' => 'inline; filename="design-set.schema.json"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }
}
