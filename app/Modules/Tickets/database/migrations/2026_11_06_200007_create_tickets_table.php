<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 20)->unique();
            $table->string('subject', 255);
            $table->text('description');
            $table->string('priority', 20);
            $table->string('status', 30);

            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            // SLA snapshot taken when the ticket is created or re-prioritised.
            $table->foreignId('sla_policy_id')->nullable()->constrained('sla_policies')->nullOnDelete();
            $table->unsignedInteger('sla_minutes');
            $table->timestamp('sla_warning_at')->nullable();
            $table->timestamp('due_at')->nullable();

            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->boolean('is_escalated')->default(false);
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->timestamp('warning_notified_at')->nullable();
            $table->timestamp('breached_notified_at')->nullable();

            $table->string('external_reference', 100)->nullable();
            $table->string('source', 20)->default('web');
            $table->timestamps();

            // Each index is justified by the query it serves in docs/DECISIONS.md (D-024).
            $table->index(['status', 'due_at']);
            $table->index(['assigned_agent_id', 'status']);
            $table->index(['created_by', 'created_at']);
            $table->index(['priority', 'status']);
            $table->index(['category_id', 'status']);
            $table->index(['status', 'sla_warning_at']);
            $table->index(['status', 'is_escalated', 'due_at']);
            $table->index('created_at');
            $table->unique(['source', 'external_reference']);

            // FULLTEXT exists only on MySQL/MariaDB; SQLite test runs fall back to LIKE.
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->fullText(['subject', 'description']);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
