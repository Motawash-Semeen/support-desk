<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aligns stored activity types with the final ActivityType values
 * (created -> ticket_created, updated -> ticket_updated, unassigned -> released).
 * Data-only; the column is a VARCHAR, so no schema change is needed.
 */
return new class extends Migration
{
    private const RENAMES = [
        'created' => 'ticket_created',
        'updated' => 'ticket_updated',
        'unassigned' => 'released',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $old => $new) {
            DB::table('ticket_activities')->where('type', $old)->update(['type' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $old => $new) {
            DB::table('ticket_activities')->where('type', $new)->update(['type' => $old]);
        }
    }
};
