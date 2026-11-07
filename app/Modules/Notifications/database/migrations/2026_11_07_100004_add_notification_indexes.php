<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications grow with every ticket event (spec section 16). Laravel's
 * default table only indexes (notifiable_type, notifiable_id); the app also:
 *  - counts unread on every page (bell)       -> (..., read_at)
 *  - lists newest first, 20 per page           -> (..., created_at)
 *  - prunes read rows older than 90 days daily -> (read_at)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_notifiable_read_at_index');
            $table->index(['notifiable_type', 'notifiable_id', 'created_at'], 'notifications_notifiable_created_at_index');
            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_notifiable_read_at_index');
            $table->dropIndex('notifications_notifiable_created_at_index');
            $table->dropIndex(['read_at']);
        });
    }
};
