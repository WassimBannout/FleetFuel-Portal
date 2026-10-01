<?php

namespace App\Support;

/**
 * Spreadsheet-safe text for CSV exports (docs/04-BUSINESS-RULES.md,
 * "Security and audit"). A spreadsheet runs a cell whose text starts with
 * =, +, - or @ (also after leading spaces), or with a tab or line break,
 * as a formula. Such text gets a leading apostrophe, which spreadsheets
 * show as text. Quoting of commas, quotes and line breaks is fputcsv's job.
 */
final class CsvCell
{
    public static function text(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return preg_match('/^ *[=+\-@\t\r\n]/', $value) === 1 ? "'".$value : $value;
    }
}
