<?php

namespace App\Support;

/**
 * PHP's shorthand byte sizes: "16M", "2G", "512K", "-1" for no limit.
 *
 * Two places need this and they must agree. The installer reports what a server allows,
 * and the uploader decides whether a request was cut off by the same limit — a picture
 * that the requirements screen called acceptable must not then be refused, and a refusal
 * must name the figure the screen showed.
 */
final class Bytes
{
    /**
     * A size in bytes. 0 means no limit, which is what PHP's "-1" and an empty value mean.
     */
    public static function parse(string $size): int
    {
        $size = trim($size);
        if ($size === '' || $size === '-1') {
            return 0;
        }
        $number = (int) $size;

        return match (strtolower(substr($size, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
