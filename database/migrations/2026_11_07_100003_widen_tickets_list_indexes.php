<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index changes driven by EXPLAIN/ANALYZE on 100k tickets (docs/PERFORMANCE.md,
 * DECISIONS D-128). Each one makes an index COVER its query, so the engine
 * answers from the index instead of one random row read per candidate. Every
 * widened index keeps its leading columns, so nothing it served before is lost
 * (and the foreign-key columns stay the leading column of an index).
 *
 *  (created_at)                -> (created_at, status)
 *      date-range COUNT did a full table scan; closed-scope list read 13k rows
 *  (status, sla_warning_at)    -> (status, sla_warning_at, due_at, warning_notified_at)
 *      "approaching" filter/dashboard/sla:scan read ~10k rows to check due_at
 *  (status, due_at)            -> (status, due_at, breached_notified_at)
 *      sla:scan breach pass: breached-but-unresolved tickets stay in range
 *  (category_id, status)       -> (category_id, status, due_at)
 *  (priority, status)          -> (priority, status, due_at)
 *      filtered lists sort by due_at
 *  (assigned_agent_id, status) -> (assigned_agent_id, status, due_at, priority,
 *                                  is_escalated, sla_warning_at, resolved_at)
 *      agent filter/queue sort by due_at; tickets-by-agent counts due_at < now;
 *      an agent's dashboard (assignee = me OR NULL) reads every counter column
 *  (status, is_escalated, due_at) -> (status, is_escalated, escalation_level, due_at)
 *      sla:scan level-2 pass turned into a full scan to check escalation_level
 *  (created_by, created_at)    -> (created_by, created_at, status)
 *      a customer's "my tickets" (always status-scoped) without row reads
 *  new (status, priority, assigned_agent_id, is_escalated, due_at, sla_warning_at)
 *      dashboard counters over active tickets and the priority-sorted list,
 *      both index-only (the counters used to scan the whole table)
 */
return new class extends Migration
{
    /**
     * [old columns, new columns, explicit name or null]. Names are given where
     * Laravel's generated name would exceed MySQL's 64-character limit.
     *
     * @var list<array{0: list<string>, 1: list<string>, 2: ?string}>
     */
    private array $widen = [
        [['created_at'], ['created_at', 'status'], null],
        [['status', 'sla_warning_at'], ['status', 'sla_warning_at', 'due_at', 'warning_notified_at'], null],
        [['status', 'due_at'], ['status', 'due_at', 'breached_notified_at'], null],
        [['category_id', 'status'], ['category_id', 'status', 'due_at'], null],
        [['assigned_agent_id', 'status'], ['assigned_agent_id', 'status', 'due_at', 'priority', 'is_escalated', 'sla_warning_at', 'resolved_at'], 'tickets_agent_cover_index'],
        [['priority', 'status'], ['priority', 'status', 'due_at'], null],
        [['status', 'is_escalated', 'due_at'], ['status', 'is_escalated', 'escalation_level', 'due_at'], null],
        [['created_by', 'created_at'], ['created_by', 'created_at', 'status'], null],
    ];

    private const COVER = ['status', 'priority', 'assigned_agent_id', 'is_escalated', 'due_at', 'sla_warning_at'];

    public function up(): void
    {
        // Add first, then drop: the FK columns always keep a supporting index.
        Schema::table('tickets', function (Blueprint $table) {
            foreach ($this->widen as [, $new, $name]) {
                $table->index($new, $name);
            }
            $table->index(self::COVER, 'tickets_dashboard_cover_index');
        });

        Schema::table('tickets', function (Blueprint $table) {
            foreach ($this->widen as [$old]) {
                $table->dropIndex($old);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            foreach ($this->widen as [$old]) {
                $table->index($old);
            }
        });

        Schema::table('tickets', function (Blueprint $table) {
            foreach ($this->widen as [, $new, $name]) {
                $table->dropIndex($name ?? $new);
            }
            $table->dropIndex('tickets_dashboard_cover_index');
        });
    }
};
