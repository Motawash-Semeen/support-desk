<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_reply_id')->nullable()->constrained('ticket_replies')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('original_name', 255);
            // UUID-based name on disk; the user-supplied name is never used as a path.
            $table->string('stored_name', 100)->unique();
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('checksum', 64);
            $table->string('disk', 30);
            $table->timestamps();

            $table->index('ticket_id');
            $table->index('ticket_reply_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
