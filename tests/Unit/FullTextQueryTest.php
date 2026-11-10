<?php

use App\Modules\Tickets\TicketFilter;

it('builds a boolean expression with required, prefix-matched words', function (string $input, ?string $expected) {
    expect(TicketFilter::booleanQuery($input))->toBe($expected);
})->with([
    'single word' => ['printer', '+printer*'],
    'several words' => ['printer jammed again', '+printer* +jammed* +again*'],
    'operators are stripped' => ['+printer -jam "paper" (tray) ~old <x> @me*', '+printer* +jam* +paper* +tray* +old*'],
    'short words dropped (below the FULLTEXT minimum)' => ['an ok vpn', '+vpn*'],
    'nothing searchable' => ['a b ++ --', null],
    'unicode letters kept' => ['ব্যাংক refund', '+ব্যাংক* +refund*'],
]);

it('caps the number of words', function () {
    $expression = TicketFilter::booleanQuery(implode(' ', array_map(fn ($i) => "word{$i}", range(1, 20))));

    expect(substr_count($expression, '+'))->toBe(TicketFilter::MAX_WORDS);
});

it('escapes LIKE wildcards', function () {
    expect(TicketFilter::escapeLike('50%_off\\'))->toBe('50\\%\\_off\\\\');
});
