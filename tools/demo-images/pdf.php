<?php

/*
 * THE DEMO'S DOCUMENTS (PLAN.md D-213): the few PDFs The Printworks offers in its Downloads
 * blocks, one per language, written from tools/demo-images/documents.php into
 * demo_images/files/. Run by hand, never by the site: php tools/demo-images/pdf.php
 *
 * One A4 page each, Helvetica, no library. A PDF's standard fonts know Croatian's letters by
 * name (ccaron, cacute, dcroat…) but WinAnsi has no code for some of them, so those are given
 * spare codes through the font's /Differences. Like the pictures, a file once released is
 * never changed (D-211): a new version is a new name.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$documents = require __DIR__ . '/documents.php';
$out = dirname(__DIR__, 2) . '/demo_images/files';
@mkdir($out, 0775, true);

// Croatian letters WinAnsi lacks, each given a code of its own (1–6), and the rest of the text
// in WinAnsi as PHP's iconv writes it.
const EXTRA = ['č' => 1, 'Č' => 2, 'ć' => 3, 'Ć' => 4, 'đ' => 5, 'Đ' => 6];
const EXTRA_NAMES = '/ccaron /Ccaron /cacute /Cacute /dcroat /Dcroat';

/** A line of text as the bytes of a PDF string in the page's encoding. */
function pdf_text(string $text): string
{
    $bytes = '';
    foreach (mb_str_split($text) as $char) {
        if (isset(EXTRA[$char])) {
            $bytes .= chr(EXTRA[$char]);
            continue;
        }
        $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT', $char);
        $bytes .= $converted === false ? '?' : $converted;
    }

    return '(' . strtr($bytes, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
}

/**
 * One page: a title, a line under it, then paragraphs and lists, wrapped by a rough measure
 * of Helvetica's width (0.5 em a letter on average is close enough for a demo leaflet).
 *
 * @param array{title: string, subtitle: string, blocks: list<string|list<string>>} $doc
 */
function pdf_page(array $doc): string
{
    $ops = [];
    $y = 770;
    $line = static function (string $text, int $size, string $font, int $x = 60) use (&$ops, &$y): void {
        $ops[] = "BT /{$font} {$size} Tf {$x} {$y} Td " . pdf_text($text) . ' Tj ET';
        $y -= (int) round($size * 1.45);
    };
    $wrap = static function (string $text, int $width): array {
        $lines = [];
        $current = '';
        foreach (explode(' ', $text) as $word) {
            $next = $current === '' ? $word : $current . ' ' . $word;
            if (mb_strlen($next) > $width && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $next;
            }
        }
        $lines[] = $current;

        return $lines;
    };

    $line('THE PRINTWORKS', 9, 'F2');
    $y -= 18;
    $line($doc['title'], 22, 'F2');
    $line($doc['subtitle'], 11, 'F1');
    $ops[] = sprintf('0.6 w 60 %d m 535 %d l S', $y + 4, $y + 4);
    $y -= 14;
    foreach ($doc['blocks'] as $block) {
        if (is_array($block)) {
            foreach ($block as $item) {
                foreach ($wrap($item, 82) as $i => $text) {
                    $line(($i === 0 ? '–  ' : '   ') . $text, 10, 'F1', 66);
                }
            }
        } elseif (str_starts_with($block, '## ')) {
            $y -= 6;
            $line(substr($block, 3), 12, 'F2');
        } else {
            foreach ($wrap($block, 88) as $text) {
                $line($text, 10, 'F1');
            }
        }
        $y -= 6;
    }
    $y = 60;
    $line('The Printworks · 14 Foundry Lane · hello@theprintworks.example', 8, 'F1');

    return implode("\n", $ops);
}

/** The whole file: catalogue, pages, one page, two fonts, the page's content. */
function pdf_file(array $doc): string
{
    $content = pdf_page($doc);
    $font = static fn (string $base): string => "<< /Type /Font /Subtype /Type1 /BaseFont /{$base} /Encoding << /Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences [1 " . EXTRA_NAMES . '] >> >>';
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
        $font('Helvetica'),
        $font('Helvetica-Bold'),
        '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream",
        '<< /Title ' . pdf_text($doc['title']) . ' /Producer (Boxlet demo) >>',
    ];
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= 'trailer << /Size ' . (count($objects) + 1) . ' /Root 1 0 R /Info ' . count($objects) . " 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

    return $pdf;
}

foreach ($documents as $name => $languages) {
    foreach ($languages as $lang => $doc) {
        $file = "{$out}/{$name}-{$lang}.pdf";
        file_put_contents($file, pdf_file($doc));
        printf("%-40s %5d bytes\n", basename($file), filesize($file));
    }
}
