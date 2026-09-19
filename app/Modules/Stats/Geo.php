<?php

namespace App\Modules\Stats;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * The country a visitor's address belongs to (PLAN.md D-051), from DB-IP's IP-to-Country
 * Lite database: CC BY 4.0, attributed on the Statistics screen, updated monthly by DB-IP.
 *
 * The database lives in storage/geo/, outside the web root, and is optional: without it
 * every country is unknown and nothing else changes. The owner fetches it from Settings,
 * which is the only request Boxlet makes to anyone for statistics, and carries nothing
 * about a visitor; or uploads the file where the server cannot reach DB-IP.
 *
 * A file is checked before it replaces the one in use — it must open as a MaxMind DB file
 * and know a country for a well-known address — and replaces it in one rename, so a view
 * counted meanwhile reads either the old file or the new one.
 */
final class Geo
{
    /** DB-IP's monthly file; %s is the year and month. */
    public const SOURCE = 'https://download.db-ip.com/free/dbip-country-lite-%s.mmdb.gz';

    private const FILE = 'geo/dbip-country-lite.mmdb';

    /** Refused above this, uncompressed: the country file is about 8 MB, a city file 130. */
    private const MAX_BYTES = 64 * 1024 * 1024;

    /** An address every country database places, to prove a file works before it is used. */
    private const KNOWN_ADDRESS = '8.8.8.8';

    public static function path(string $storagePath): string
    {
        return $storagePath . '/' . self::FILE;
    }

    /** The country code for an address, as ISO writes it (HR); '' when unknown or there is no file. */
    public static function country(string $storagePath, string $ip): string
    {
        $path = self::path($storagePath);
        if ($ip === '' || !is_file($path)) {
            return '';
        }
        try {
            return self::code((new Mmdb($path))->get($ip));
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * The database in use, for the Settings panel: when it was built and by whom. Null
     * when there is none, or it cannot be read.
     *
     * @return array{built: string, type: string}|null
     */
    public static function status(string $storagePath): ?array
    {
        $path = self::path($storagePath);
        if (!is_file($path)) {
            return null;
        }
        try {
            $metadata = (new Mmdb($path))->metadata();
        } catch (Throwable) {
            return null;
        }
        $epoch = $metadata['build_epoch'] ?? null;

        return [
            'built' => is_int($epoch) ? gmdate('Y-m-d', $epoch) : '',
            'type' => is_string($metadata['database_type'] ?? null) ? $metadata['database_type'] : '',
        ];
    }

    /**
     * Fetches DB-IP's file for this month, or last month's when this month's is not out
     * yet (it appears on the first, in DB-IP's time), and puts it in use.
     *
     * @throws RuntimeException with a message for the owner
     */
    public static function download(string $storagePath, DateTimeImmutable $now, string $source = self::SOURCE): void
    {
        $directory = self::directory($storagePath);
        $part = $directory . '/download.mmdb.gz.part';
        $failure = '';
        foreach ([$now, $now->modify('first day of last month')] as $month) {
            $failure = self::fetch(sprintf($source, $month->format('Y-m')), $part);
            if ($failure === '') {
                try {
                    self::install($storagePath, $part);
                } finally {
                    self::remove($part);
                }

                return;
            }
        }
        self::remove($part);

        throw new RuntimeException(t('stats.geo_download_failed', ['reason' => $failure]));
    }

    /**
     * Puts a database in use: $source is a .mmdb file, or one gzipped as DB-IP sends it.
     * $source is left where it is; the caller removes it.
     *
     * @throws RuntimeException with a message for the owner
     */
    public static function install(string $storagePath, string $source): void
    {
        $directory = self::directory($storagePath);
        $unpacked = $directory . '/install.mmdb.part';
        try {
            self::unpack($source, $unpacked);
            try {
                $known = self::code((new Mmdb($unpacked))->get(self::KNOWN_ADDRESS));
            } catch (Throwable) {
                $known = '';
            }
            if ($known === '') {
                throw new RuntimeException(t('stats.geo_not_a_database'));
            }
            if (!@rename($unpacked, self::path($storagePath))) {
                throw new RuntimeException(t('stats.geo_not_saved'));
            }
        } finally {
            self::remove($unpacked);
        }
    }

    /** Copies $source to $target, unpacking it if it is gzipped, up to MAX_BYTES. */
    private static function unpack(string $source, string $target): void
    {
        $head = (string) @file_get_contents($source, false, null, 0, 2);
        $in = $head === "\x1f\x8b" ? @gzopen($source, 'rb') : @fopen($source, 'rb');
        $out = @fopen($target, 'wb');
        if ($in === false || $out === false) {
            throw new RuntimeException(t('stats.geo_not_saved'));
        }
        $written = 0;
        $gz = $head === "\x1f\x8b";
        while (!($gz ? gzeof($in) : feof($in))) {
            $chunk = $gz ? gzread($in, 1 << 20) : fread($in, 1 << 20);
            if ($chunk === false) {
                break;
            }
            $written += strlen($chunk);
            if ($written > self::MAX_BYTES) {
                break;
            }
            fwrite($out, $chunk);
        }
        $gz ? gzclose($in) : fclose($in);
        fclose($out);
        if ($written > self::MAX_BYTES) {
            throw new RuntimeException(t('stats.geo_too_big'));
        }
    }

    /**
     * Downloads $url to $target. Returns '' on success, else what went wrong in a few
     * words. curl where PHP has it, since a shared host that turns off allow_url_fopen
     * usually still has curl; PHP's own streams otherwise.
     */
    private static function fetch(string $url, string $target): string
    {
        if (function_exists('curl_init') && str_starts_with($url, 'https://')) {
            $out = @fopen($target, 'wb');
            if ($out === false) {
                return t('stats.geo_not_saved');
            }
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_FILE => $out,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_FAILONERROR => true,
                CURLOPT_USERAGENT => 'Boxlet',
            ]);
            $done = curl_exec($curl);
            $error = $done === true ? '' : (curl_error($curl) !== '' ? curl_error($curl) : 'HTTP ' . curl_getinfo($curl, CURLINFO_RESPONSE_CODE));
            curl_close($curl);
            fclose($out);

            return $error;
        }

        try {
            $in = @fopen($url, 'rb', false, stream_context_create(['http' => ['timeout' => 120, 'user_agent' => 'Boxlet']]));
        } catch (Throwable $e) {
            // An error handler that ignores @ turns the warning into this.
            return $e->getMessage();
        }
        if ($in === false) {
            return error_get_last()['message'] ?? 'no connection';
        }
        $copied = @file_put_contents($target, $in);
        fclose($in);

        return $copied === false ? t('stats.geo_not_saved') : '';
    }

    private static function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }

    private static function directory(string $storagePath): string
    {
        $directory = dirname(self::path($storagePath));
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException(t('stats.geo_not_saved'));
        }

        return $directory;
    }

    /** The country code in a looked-up value, upper case, or ''. */
    private static function code(mixed $value): string
    {
        $code = is_array($value) && is_array($value['country'] ?? null) ? ($value['country']['iso_code'] ?? '') : '';

        return is_string($code) && preg_match('~^[A-Za-z]{2}$~', $code) === 1 ? strtoupper($code) : '';
    }
}
