<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function indexColumns(string $table): array
{
    return collect(Schema::getIndexes($table))
        ->mapWithKeys(fn (array $index) => [implode(',', $index['columns']) => $index])
        ->all();
}

it('creates every table', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with([
    'users', 'categories', 'agent_category', 'sla_policies', 'business_hours', 'holidays',
    'ticket_sequences', 'tickets', 'ticket_replies', 'attachments', 'ticket_activities',
    'ticket_imports', 'ticket_import_errors', 'notifications', 'jobs', 'failed_jobs',
]);

it('indexes the tickets table for every list, filter and scanner query', function () {
    $indexes = indexColumns('tickets');

    expect($indexes['ticket_number']['unique'])->toBeTrue()
        ->and($indexes['source,external_reference']['unique'])->toBeTrue();

    foreach ([
        'status,due_at',
        'assigned_agent_id,status',
        'created_by,created_at',
        'priority,status',
        'category_id,status',
        'status,sla_warning_at',
        'status,is_escalated,due_at',
        'created_at',
    ] as $columns) {
        expect($indexes)->toHaveKey($columns);
    }
});

it('adds a FULLTEXT index on MySQL only', function () {
    $fulltext = collect(Schema::getIndexes('tickets'))->firstWhere('columns', ['subject', 'description']);

    if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        expect($fulltext)->not->toBeNull()
            ->and(strtolower($fulltext['type']))->toBe('fulltext');
    } else {
        expect($fulltext)->toBeNull();
    }
});

it('indexes replies, activities, attachments, imports and assignment lookups', function () {
    expect(indexColumns('ticket_replies'))->toHaveKeys(['ticket_id,created_at', 'ticket_id,type'])
        ->and(indexColumns('ticket_activities'))->toHaveKeys(['ticket_id,created_at', 'type,created_at'])
        ->and(indexColumns('attachments'))->toHaveKeys(['ticket_id', 'ticket_reply_id'])
        ->and(indexColumns('ticket_import_errors'))->toHaveKey('ticket_import_id,row_number')
        ->and(indexColumns('users'))->toHaveKey('role,is_available,last_assigned_at')
        ->and(indexColumns('agent_category'))->toHaveKeys(['user_id,category_id', 'category_id,user_id']);
});

it('keeps ticket_activities append-only at the schema level', function () {
    expect(Schema::hasColumn('ticket_activities', 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn('ticket_activities', 'updated_at'))->toBeFalse();
});
