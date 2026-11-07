<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Error tracker: one row per distinct error (fingerprint), with an occurrence
 * counter and the details of the latest occurrence. Fed by the `tracker` log
 * channel (every log entry at error level or above, which includes every
 * reported exception) and by TrackHttpErrors (4xx responses, grouped by status).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_logs', function (Blueprint $table) {
            $table->id();
            $table->char('fingerprint', 40)->unique();
            $table->string('level', 20);
            $table->string('source', 20); // exception | log | http
            $table->text('message');
            $table->string('exception_class')->nullable();
            $table->string('file', 500)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->mediumText('trace')->nullable();
            $table->json('context')->nullable();
            $table->string('url', 2000)->nullable();
            $table->string('method', 10)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('ip', 45)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('occurrences')->default(1);
            // useCurrent(): MariaDB without explicit_defaults_for_timestamp rejects
            // a second NOT NULL timestamp that has no default.
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Admin list: open/resolved tab sorted by last seen; level filter; prune.
            $table->index(['resolved_at', 'last_seen_at']);
            $table->index(['level', 'last_seen_at']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_logs');
    }
};
