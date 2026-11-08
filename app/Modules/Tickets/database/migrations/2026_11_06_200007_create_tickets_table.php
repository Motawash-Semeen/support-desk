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

            // Each index covers the queries it serves, so they are answered from the
            // index alone (measured on 100k tickets: docs/PERFORMANCE.md, D-024, D-128).
            // Laravel's generated name for the two long ones would pass MySQL's 64-character limit.
            $table->index(['status', 'due_at', 'breached_notified_at']);                    // active lists by due date; sla:scan breach pass
            $table->index(['status', 'sla_warning_at', 'due_at', 'warning_notified_at']);   // "at risk" filter; sla:scan warning pass
            $table->index(['status', 'resolved_at']);                                       // auto-close of resolved tickets
            $table->index(['status', 'is_escalated', 'escalation_level', 'due_at']);        // escalated filter; sla:scan level-2 pass
            $table->index(['status', 'priority', 'assigned_agent_id', 'is_escalated', 'due_at', 'sla_warning_at'], 'tickets_dashboard_cover_index');
            $table->index(['assigned_agent_id', 'status', 'due_at', 'priority', 'is_escalated', 'sla_warning_at', 'resolved_at'], 'tickets_agent_cover_index');
            $table->index(['created_by', 'created_at', 'status']);                          // a customer's own tickets
            $table->index(['priority', 'status', 'due_at']);                                // priority filter
            $table->index(['category_id', 'status', 'due_at']);                             // category filter
            $table->index(['created_at', 'status']);                                        // date range; newest-first lists
            $table->index('updated_at');                                                    // "last update" sort
            $table->unique(['source', 'external_reference']);                               // import duplicates

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
