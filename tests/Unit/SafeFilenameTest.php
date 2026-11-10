<?php

use App\Support\SafeFilename;

it('cleans user-supplied file names', function (string $input, string $expected) {
    expect(SafeFilename::clean($input))->toBe($expected);
})->with([
    'plain' => ['invoice.pdf', 'invoice.pdf'],
    'path traversal' => ['../../etc/passwd.txt', 'passwd.txt'],
    'windows path' => ['C:\\Users\\me\\report.docx', 'report.docx'],
    'header injection' => ["evil\r\nSet-Cookie: x.pdf", 'evilSet-Cookie_ x.pdf'],
    'quotes and separators' => ['a"b<c>d|e?.pdf', 'a_b_c_d_e_.pdf'],
    'only dots' => ['...', 'attachment'],
    'empty' => ['', 'attachment'],
]);

it('limits the length but keeps the extension', function () {
    $name = SafeFilename::clean(str_repeat('a', 400).'.xlsx');

    expect(strlen($name))->toBeLessThanOrEqual(SafeFilename::MAX_LENGTH)
        ->and($name)->toEndWith('.xlsx');
});
