<?php

namespace App\Modules\Tickets\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Issues human-readable ticket numbers, e.g. TKT-2026-000001, from a per-year
 * counter row locked with SELECT ... FOR UPDATE.
 *
 * Call it inside the transaction that inserts the ticket: the counter update
 * then commits or rolls back with the ticket, so numbers stay gapless. Called
 * on its own, it runs in (and commits) its own short transaction.
 *
 * Padding is a minimum width: number 1,000,000 becomes TKT-2026-1000000
 * (the column is VARCHAR(20)). The UNIQUE index on tickets.ticket_number is
 * the final safety net.
 */
class TicketNumberGenerator
{
    public function generate(?CarbonInterface $at = null): string
    {
        return $this->reserve(1, $at)[0];
    }

    /**
     * Allocate a contiguous block of numbers with a single lock (bulk import).
     *
     * @return list<string>
     */
    public function reserve(int $count, ?CarbonInterface $at = null): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException('At least one ticket number must be reserved.');
        }

        $year = ($at ?? now())->year;

        $last = DB::transaction(function () use ($year, $count): int {
            DB::table('ticket_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);

            $current = (int) DB::table('ticket_sequences')
                ->where('year', $year)
                ->lockForUpdate()
                ->value('last_number');

            DB::table('ticket_sequences')
                ->where('year', $year)
                ->update(['last_number' => $current + $count]);

            return $current + $count;
        });

        return array_map(
            fn (int $number): string => $this->format($year, $number),
            range($last - $count + 1, $last),
        );
    }

    public function format(int $year, int $number): string
    {
        return sprintf(
            '%s-%d-%0'.(int) config('support.ticket_number_padding').'d',
            config('support.ticket_prefix'),
            $year,
            $number,
        );
    }
}
