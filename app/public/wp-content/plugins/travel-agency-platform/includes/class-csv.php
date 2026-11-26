<?php
defined('ABSPATH') || exit;

/** Single CSV writer helper: formula-injection safe cell escaping. */
class TAP_Csv {

    /** Neutralize spreadsheet formulas before writing a cell. */
    public static function cell($value) {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $line = (string) $value;
        // Covers =, +, @, tabs/CR, and a leading dash followed by non-numeric
        // text. Numbers such as -12.50 stay untouched.
        if (preg_match('/^[\x00-\x20]*[=+@\t\r]/', $line) || preg_match('/^-(?![0-9.])/', $line)) {
            $line = "'" . $line;
        }
        return $line;
    }

    /** Write one CSV row through php://output using the safe cell helper. */
    public static function row($handle, array $values) {
        fputcsv($handle, array_map([__CLASS__, 'cell'], $values));
    }
}
