<?php

namespace App\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Reads the first sheet of an import file into a grid (list of rows, each a list of cells). Excel (.xlsx/.xls) goes
 * through PhpSpreadsheet. CSV (TOR 4.1.1(2)-1, 4.4-2) is read here, because files saved from Thai Excel are often
 * not UTF-8:
 * - A UTF-8 BOM is dropped. Text that isn't valid UTF-8 is converted from TIS-620/Windows-874 (Thai Windows).
 * - The delimiter is whichever of comma, semicolon or tab appears most in the header line.
 * - Quoted cells may hold commas and line breaks (EVENT_DESCRIPTION).
 * CSV cells are strings; ImportValidator parses numbers and dates from strings the same way it does from Excel.
 */
class SpreadsheetGrid
{
    public const EXTENSIONS = ['xlsx', 'xls', 'csv'];

    /**
     * @return list<list<mixed>>
     *
     * @throws RuntimeException when the file can't be read
     */
    public static function load(string $path, ?string $extension = null): array
    {
        $extension = strtolower($extension ?? pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['csv', 'txt'], true)) {
            return self::csv($path);
        }

        return IOFactory::load($path)->getSheet(0)->toArray(null, true, false, false);
    }

    /**
     * @return list<list<string|null>>
     */
    private static function csv(string $path): array
    {
        $text = @file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException('อ่านไฟล์ไม่ได้');
        }
        $text = self::toUtf8($text);

        $firstLine = strtok($text, "\r\n") ?: '';
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $text);
        rewind($stream);
        $grid = [];
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if ($cells === [null]) {
                $grid[] = [];

                continue;
            }
            $grid[] = array_map(fn ($cell) => $cell === null ? null : (string) $cell, $cells);
        }
        fclose($stream);

        return $grid;
    }

    private static function toUtf8(string $text): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $converted = @iconv('CP874', 'UTF-8//IGNORE', $text);
        if ($converted === false || $converted === '') {
            $converted = @iconv('TIS-620', 'UTF-8//IGNORE', $text);
        }
        if ($converted === false) {
            throw new RuntimeException('อ่านภาษาไทยในไฟล์ไม่ได้');
        }

        return $converted;
    }
}
