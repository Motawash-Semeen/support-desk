<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            // One policy per priority; the unique index also serves the lookup by priority.
            $table->string('priority', 20)->unique();
            $table->unsignedInteger('resolution_minutes');
            $table->decimal('warning_threshold', 3, 2)->default(0.80);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_policies');
    }
};
