<?php
/**
 * Minimal, dependency-free PDF writer for styled invoices.
 *
 * Produces a valid one-page PDF 1.4 using the standard Helvetica fonts (with
 * WinAnsiEncoding so accented Spanish text renders). No external library, so
 * no extra supply-chain surface. Elements are structured arrays, with plain
 * strings still accepted as ordinary body text for backwards compatibility.
 */
defined('ABSPATH') || exit;

class TAP_Simple_PDF {

    const PAGE_W = 595;
    const PAGE_H = 842;
    const MARGIN = 50;

    const INK    = [0.12, 0.16, 0.23];
    const MUTED  = [0.40, 0.45, 0.52];
    const PRIMARY = [0.06, 0.36, 0.46];
    const LINE   = [0.85, 0.88, 0.92];
    const BAND   = [0.94, 0.96, 0.98];

    /**
     * Build a one-page PDF.
     *
     * @param array<int,mixed> $elements Strings or structured elements:
     *   brand, title, subtitle, h, text, small, row, total, rule, spacer, band.
     * @return string PDF bytes.
     */
    public static function build(array $elements) {
        $content = self::stream($elements);

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::PAGE_W . ' ' . self::PAGE_H . '] '
                . '/Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            6 => '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = count($objects);
        $pdf .= "xref\n0 " . ($count + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . ($count + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }

    /** Translate the element list into PDF drawing operators. */
    private static function stream(array $elements) {
        $out = '';
        $y = self::PAGE_H - self::MARGIN;
        $left = self::MARGIN;
        $right = self::PAGE_W - self::MARGIN;
        $width = $right - $left;

        foreach ($elements as $element) {
            if (!is_array($element)) {
                $element = ['type' => 'text', 'text' => (string) $element];
            }
            $type = (string) ($element['type'] ?? 'text');

            if ('spacer' === $type) {
                $y -= max(2, min(120, (int) ($element['h'] ?? 10)));
                continue;
            }
            if ($y < 64) { break; }

            switch ($type) {
                case 'brand':
                    self::rect($out, $left, $y - 16, $width, 56, self::PRIMARY);
                    $out .= self::text($element['text'] ?? '', $left + 18, $y + 6, 18, true, [1, 1, 1]);
                    $y -= 62;
                    break;

                case 'title':
                    $out .= self::text($element['text'] ?? '', $left, $y, 22, true, self::INK);
                    $y -= 32;
                    break;

                case 'subtitle':
                    $out .= self::text($element['text'] ?? '', $left, $y, 10, false, self::MUTED);
                    $y -= 22;
                    break;

                case 'h':
                    $y -= 8;
                    $out .= self::text($element['text'] ?? '', $left, $y, 12, true, self::PRIMARY);
                    $y -= 20;
                    break;

                case 'rule':
                    $y -= 6;
                    $out .= self::line($left, $y, $right, $y, self::LINE);
                    $y -= 12;
                    break;

                case 'band':
                    self::rect($out, $left, $y - 14, $width, 26, self::BAND);
                    $out .= self::text($element['text'] ?? '', $left + 10, $y - 6, 10, true, self::INK);
                    $y -= 32;
                    break;

                case 'row':
                    $label = (string) ($element['label'] ?? '');
                    $value = (string) ($element['value'] ?? '');
                    $out .= self::text($label, $left, $y, 10, false, self::MUTED);
                    $out .= self::text_right($value, $right, $y, 10, !empty($element['strong']), self::INK);
                    $y -= 18;
                    break;

                case 'total':
                    self::rect($out, $left, $y - 14, $width, 34, self::BAND);
                    $out .= self::text($element['label'] ?? '', $left + 10, $y - 3, 12, true, self::INK);
                    $out .= self::text_right($element['value'] ?? '', $right - 10, $y - 3, 13, true, self::PRIMARY);
                    $y -= 40;
                    break;

                case 'small':
                    $out .= self::text($element['text'] ?? '', $left, $y, 9, false, self::MUTED);
                    $y -= 14;
                    break;

                case 'text':
                default:
                    $out .= self::text($element['text'] ?? ($element['value'] ?? ''), $left, $y, 11, false, self::INK);
                    $y -= 18;
                    break;
            }
        }
        return $out;
    }

    /** Draw a filled rectangle. */
    private static function rect(&$out, $x, $y, $w, $h, $color) {
        $out .= self::rgb($color, false)
            . sprintf('%.2F %.2F %.2F %.2F re f', $x, $y, $w, $h) . "\n";
    }

    private static function line($x1, $y1, $x2, $y2, $color) {
        return self::rgb($color, true)
            . '0.8 w '
            . sprintf('%.2F %.2F m %.2F %.2F l S', $x1, $y1, $x2, $y2) . "\n";
    }

    /** Emit a left-aligned text run. */
    private static function text($value, $x, $y, $size, $bold, $color) {
        return 'BT ' . ($bold ? '/F2' : '/F1') . ' ' . (int) $size . ' Tf '
            . self::rgb($color, false)
            . sprintf('%.2F %.2F Td (%s) Tj ET', $x, $y, self::escape(self::sanitize($value))) . "\n";
    }

    /** Emit a right-aligned text run (approximate width from the string length). */
    private static function text_right($value, $right, $y, $size, $bold, $color) {
        $clean = self::sanitize($value);
        $width = strlen($clean) * (int) $size * ($bold ? 0.58 : 0.54);
        return self::text($clean, max(self::MARGIN, $right - $width), $y, $size, $bold, $color);
    }

    private static function rgb($color, $stroke) {
        $r = max(0, min(1, (float) ($color[0] ?? 0)));
        $g = max(0, min(1, (float) ($color[1] ?? 0)));
        $b = max(0, min(1, (float) ($color[2] ?? 0)));
        return sprintf('%.3F %.3F %.3F %s', $r, $g, $b, $stroke ? 'RG' : 'rg') . "\n";
    }

    /** Latin-1 transliteration + control-char stripping for Helvetica. */
    private static function sanitize($value) {
        $text = (string) $value;
        $text = str_replace(['—', '–', '₡', '₽'], ['-', '-', 'CRC', 'RUB'], $text);
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
