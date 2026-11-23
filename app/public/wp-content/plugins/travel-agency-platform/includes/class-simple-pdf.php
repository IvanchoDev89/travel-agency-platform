<?php
/**
 * Minimal, dependency-free PDF writer for planar text invoices.
 * Produces a valid one-page PDF 1.4 with Helvetica; no external library,
 * so no extra supply-chain surface. Keep it deliberately small.
 */
defined('ABSPATH') || exit;

class TAP_Simple_PDF {

    /**
     * Build a one-page PDF from an array of text lines.
     *
     * @param array<int,string> $lines
     * @return string PDF bytes.
     */
    public static function build(array $lines) {
        $content = '';
        $y = 780;
        foreach ($lines as $line) {
            if ($y < 50) { break; }
            $content .= 'BT /F1 11 Tf 50 ' . $y . ' Td (' . self::escape(self::sanitize($line)) . ") Tj ET\n";
            $y -= 18;
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
                . '/Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            5 => '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $object_count = count($objects);
        $pdf .= 'xref' . "\n0 " . ($object_count + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $object_count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= 'trailer' . "\n<< /Size " . ($object_count + 1) . " /Root 1 0 R >>\n";
        $pdf .= 'startxref' . "\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }

    /** Latin-1 transliteration + control-char stripping for Helvetica. */
    private static function sanitize($value) {
        $text = (string) $value;
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
            if (false !== $converted) { $text = $converted; }
        }
        return (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $text);
    }

    /** Escape the three characters with special meaning in PDF strings. */
    private static function escape($text) {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
