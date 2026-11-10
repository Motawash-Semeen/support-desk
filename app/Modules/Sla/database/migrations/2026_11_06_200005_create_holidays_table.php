<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            // Calendar date in the business timezone.
            $table->date('date')->unique();
            $table->string('name', 100);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
