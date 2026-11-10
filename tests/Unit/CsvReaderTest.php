<?php

use App\Modules\Imports\CsvReader;
use Illuminate\Support\LazyCollection;

function csvFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, $contents);

    return $path;
}

it('normalises the header and strips a UTF-8 BOM', function () {
    $reader = new CsvReader(csvFile("\xEF\xBB\xBFExternal_Reference , Customer_Email\nA,b@example.com\n"));

    expect($reader->header())->toBe(['external_reference', 'customer_email']);
});

it('counts data rows in one streaming pass, ignoring blank lines', function () {
    $reader = new CsvReader(csvFile("a,b\n1,2\n\n3,4\n5,6\n"));

    expect($reader->count())->toBe(3);
});

it('yields rows with spreadsheet row numbers (header = row 1)', function () {
    $rows = (new CsvReader(csvFile("a,b\n 1 , 2 \n3,4\n")))->rows()->all();

    expect($rows[0])->toBe(['row' => 2, 'data' => ['a' => '1', 'b' => '2'], 'raw' => ['1', '2']])
        ->and($rows[1]['row'])->toBe(3);
});

it('keeps quoted commas and line breaks inside one field', function () {
    $rows = (new CsvReader(csvFile("a,b\n\"x, y\",\"line1\nline2\"\n")))->rows()->all();

    expect($rows[0]['data'])->toBe(['a' => 'x, y', 'b' => "line1\nline2"]);
});

it('flags rows whose column count differs from the header', function () {
    $rows = (new CsvReader(csvFile("a,b\n1,2,3\n")))->rows()->all();

    expect($rows[0]['data'])->toBeNull()->and($rows[0]['raw'])->toBe(['1', '2', '3']);
});

it('streams lazily (nothing is read until iterated)', function () {
    expect((new CsvReader(csvFile("a\n1\n")))->rows())->toBeInstanceOf(LazyCollection::class);
});
