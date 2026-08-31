<?php

namespace App\Support;

/**
 * CSV writing that is safe to open in Excel.
 *
 * Spreadsheets treat a cell beginning with = + - @ (or a control character)
 * as a FORMULA. Worker data routinely starts that way — Indian phone numbers
 * are stored as "+919876543210" — and anything worse ("=cmd|'/C calc'!A0")
 * turns an exported register into code execution on the clerk's machine
 * (CSV injection). Antivirus and Excel both flag such files.
 *
 * We prefix those cells with an apostrophe, which Excel consumes silently and
 * renders as plain text. Our own importer and .xlsx converter strip it back
 * off, so an exported file still re-imports byte-for-byte in meaning.
 */
class Csv
{
    /** Neutralise one cell for spreadsheet consumption. */
    public static function cell($value): string
    {
        $v = (string) ($value ?? '');
        if ($v === '') {
            return $v;
        }

        return preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
    }

    /** fputcsv with every cell neutralised. */
    public static function row($handle, array $values): void
    {
        fputcsv($handle, array_map([self::class, 'cell'], $values));
    }

    /** Undo cell(): strip the guard apostrophe when reading a file back in. */
    public static function unguard($value): string
    {
        $v = (string) ($value ?? '');

        return preg_match("/^'[=+\-@\t\r]/", $v) ? substr($v, 1) : $v;
    }
}
