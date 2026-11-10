<?php

namespace App\Modules\Imports;

use Illuminate\Support\LazyCollection;
use RuntimeException;
use SplFileObject;

/**
 * Streams a CSV file row by row, so memory stays flat. Header names are trimmed,
 * lower-cased and stripped of a BOM. rows() gives the spreadsheet row number and
 * the data (null when the column count does not match the header).
 */
final class CsvReader
{
    public function __construct(private readonly string $path)
    {
        if (! is_readable($path)) {
            throw new RuntimeException("CSV file not readable: {$path}");
        }
    }

    public function header(): array
    {
        foreach ($this->records() as $record) {
            return $this->normaliseHeader($record);
        }

        return [];
    }

    /** Counts the data rows (for the progress bar). */
    public function count(): int
    {
        $count = -1; // minus the header

        foreach ($this->records() as $_) {
            $count++;
        }

        return max(0, $count);
    }

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

    private function normaliseHeader(array $record): array
    {
        return array_map(
            fn ($name) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $name) ?? '')),
            $record,
        );
    }
}
