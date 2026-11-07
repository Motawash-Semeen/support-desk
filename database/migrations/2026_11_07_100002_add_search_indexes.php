<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the new ticket list options:
 *  - tickets(updated_at): sort=updated_at (every sort column is indexed);
 *  - users(role, name): customer autocomplete, WHERE role = 'customer' AND name LIKE 'prefix%'.
 *    (email prefix searches use the existing unique index on email.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->index('updated_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index(['role', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['updated_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role', 'name']);
        });
    }
};
