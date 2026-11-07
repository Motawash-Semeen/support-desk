<?php

namespace App\Modules\Imports\Support;

use Illuminate\Support\LazyCollection;
use RuntimeException;
use SplFileObject;

/**
 * Streams a CSV file one record at a time (SplFileObject + generators), so
 * memory stays flat whatever the file size. The header row is read once;
 * header names are trimmed, lower-cased and stripped of a UTF-8 BOM.
 *
 * rows() yields ['row' => int, 'data' => array<string, string>|null] where
 * `row` is the CSV record number counting the header as row 1 (what a user
 * sees in a spreadsheet), and `data` is null when the record has a different
 * number of columns than the header.
 */
final class CsvReader
{
    public function __construct(private readonly string $path)
    {
        if (! is_readable($path)) {
            throw new RuntimeException("CSV file not readable: {$path}");
        }
    }

    /** @return list<string> */
    public function header(): array
    {
        foreach ($this->records() as $record) {
            return $this->normaliseHeader($record);
        }

        return [];
    }

    /** One streaming pass that only counts data records (for the progress bar). */
    public function count(): int
    {
        $count = -1; // minus the header

        foreach ($this->records() as $_) {
            $count++;
        }

        return max(0, $count);
    }

    /** @return LazyCollection<int, array{row: int, data: array<string, string>|null, raw: list<string|null>}> */
    public function rows(): LazyCollection
    {
        return LazyCollection::make(function () {
            $header = null;
            $row = 0;

            foreach ($this->records() as $record) {
                $row++;

                if ($header === null) {
                    $header = $this->normaliseHeader($record);

                    continue;
                }

                $values = array_map(fn ($value) => $value === null ? '' : trim((string) $value), $record);

                yield [
                    'row' => $row,
                    'data' => count($values) === count($header) ? array_combine($header, $values) : null,
                    'raw' => $values,
                ];
            }
        });
    }

    /** @return \Generator<int, list<string|null>> */
    private function records(): \Generator
    {
        $file = new SplFileObject($this->path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl(',', '"', '\\');

        foreach ($file as $record) {
            if ($record === false || $record === [null]) {
                continue;
            }

            yield $record;
        }
    }

    /** @return list<string> */
    private function normaliseHeader(array $record): array
    {
        return array_map(
            fn ($name) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $name) ?? '')),
            $record,
        );
    }
}
