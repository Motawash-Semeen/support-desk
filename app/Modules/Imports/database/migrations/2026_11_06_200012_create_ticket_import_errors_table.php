<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw_data');
            $table->json('errors');
            $table->timestamp('created_at')->useCurrent();

            // Error report for one import, in file order.
            $table->index(['ticket_import_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_import_errors');
    }
};
