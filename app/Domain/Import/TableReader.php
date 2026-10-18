<?php

namespace App\Domain\Import;

use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Reads CSV (comma or semicolon, with or without BOM, UTF-8 or Windows-1250) and XLSX exports
 * into rows of strings. Used by the data import and the translation import.
 */
class TableReader
{
    /** @return iterable<int, list<string>> */
    public static function rows(string $path, string $extension, int $limit = 200000): iterable
    {
        $extension = strtolower($extension);
        $count = 0;
        if ($extension === 'xlsx') {
            $reader = new XlsxReader;
            $reader->open($path);
            try {
                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $row) {
                        if (++$count > $limit) {
                            throw new RuntimeException('Too many rows');
                        }
                        yield array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i') : (is_scalar($v) ? (string) $v : ''), $row->toArray());
                    }
                    break;
                }
            } finally {
                $reader->close();
            }

            return;
        }
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1250');
        }
        $first = strtok($content, "\n");
        $delimiter = substr_count((string) $first, ';') >= substr_count((string) $first, ',') ? ';' : ',';
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        try {
            while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (++$count > $limit) {
                    throw new RuntimeException('Too many rows');
                }
                yield array_map(fn ($v) => (string) $v, $row);
            }
        } finally {
            fclose($handle);
        }
    }
}
