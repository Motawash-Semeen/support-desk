<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_hours', function (Blueprint $table) {
            $table->id();
            // Carbon dayOfWeek: 0 = Sunday ... 6 = Saturday. One row per weekday.
            $table->unsignedTinyInteger('weekday')->unique();
            $table->boolean('is_working_day')->default(true);
            // Wall-clock times in config('support.timezone').
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_hours');
    }
};
