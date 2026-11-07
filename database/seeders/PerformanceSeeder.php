<?php

namespace Database\Seeders;

use App\Enums\ActivityType;
use App\Enums\ReplyType;
use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Models\Category;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Bulk volume for EXPLAIN measurements (docs/PERFORMANCE.md). Never part of
 * DatabaseSeeder. Run against a throwaway database:
 *
 *   DB_DATABASE=support_desk_perf php artisan migrate:fresh --force
 *   DB_DATABASE=support_desk_perf php artisan db:seed --class=PerformanceSeeder --force
 *
 * Volumes (config support.performance, env PERF_TICKETS etc.): 100k tickets, 300k replies,
 * 50k attachment rows, ~400k activities, 5k customers, 40 agents.
 *
 * Plain chunked multi-row INSERTs (no models, no events): about 2,000 rows
 * per statement, one transaction per chunk. Distributions are deterministic
 * (mt_srand) so measurements are repeatable:
 *   - created_at spread over 730 days before "now";
 *   - 70% resolved/closed, 30% active (open / in progress / waiting);
 *   - priority 40% low, 35% medium, 18% high, 7% critical;
 *   - about 8% of active tickets breached, 10% inside the warning window;
 *   - 3 replies per ticket on average, 15% of them internal notes.
 */
class PerformanceSeeder extends Seeder
{
    private const CHUNK = 2000;

    public function run(): void
    {
        mt_srand(20261109);
        DB::disableQueryLog();

        $tickets = (int) config('support.performance.tickets');
        $replies = (int) config('support.performance.replies');
        $attachments = (int) config('support.performance.attachments');

        $this->call([CategorySeeder::class, SlaPolicySeeder::class, BusinessHourSeeder::class]);

        $now = CarbonImmutable::now()->startOfMinute();
        [$customerIds, $agentIds, $adminId] = $this->users($now);
        $categoryIds = Category::query()->pluck('id')->all();

        // Bulk-load technique (this throwaway database only): session-level
        // checks off, secondary indexes that no foreign key needs are dropped
        // and rebuilt once, sorted, after the load. Same final schema.
        DB::statement('SET SESSION unique_checks = 0, foreign_key_checks = 0');
        $dropped = $this->dropSecondaryIndexes(['tickets', 'ticket_replies', 'ticket_activities', 'attachments']);

        $this->info("Tickets: {$tickets}");
        $this->tickets($tickets, $now, $customerIds, $agentIds, $categoryIds);

        $this->info("Replies + activities: {$replies}");
        $this->replies($replies, $now, $customerIds, $agentIds);

        $this->info("Attachments: {$attachments}");
        $this->attachments($attachments);

        $this->info('Rebuilding '.count($dropped).' indexes');
        $this->restoreIndexes($dropped);
        DB::statement('SET SESSION unique_checks = 1, foreign_key_checks = 1');

        DB::statement('ANALYZE TABLE tickets, ticket_replies, attachments, ticket_activities, users');
        $this->info('Done.');
    }

    /**
     * Drops every secondary index whose leading column is not a foreign-key
     * column (those must stay for the constraints), returning definitions.
     *
     * @param  list<string>  $tables
     * @return list<array{table: string, name: string, unique: bool, fulltext: bool, columns: list<string>}>
     */
    private function dropSecondaryIndexes(array $tables): array
    {
        $schema = DB::getDatabaseName();
        $dropped = [];

        foreach ($tables as $table) {
            $fkColumns = collect(DB::select(
                'SELECT column_name AS c FROM information_schema.key_column_usage WHERE table_schema = ? AND table_name = ? AND referenced_table_name IS NOT NULL',
                [$schema, $table],
            ))->pluck('c')->map(fn ($c) => strtolower($c))->all();

            $indexes = collect(DB::select(
                'SELECT index_name AS name, non_unique, index_type, column_name AS col, seq_in_index FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name <> ? ORDER BY index_name, seq_in_index',
                [$schema, $table, 'PRIMARY'],
            ))->groupBy('name');

            foreach ($indexes as $name => $columns) {
                $first = $columns->first();
                if (in_array(strtolower($first->col), $fkColumns, true)) {
                    continue;
                }

                $dropped[] = [
                    'table' => $table, 'name' => (string) $name, 'unique' => ! (int) $first->non_unique,
                    'fulltext' => $first->index_type === 'FULLTEXT', 'columns' => $columns->pluck('col')->all(),
                ];
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
            }
        }

        return $dropped;
    }

    /** @param  list<array{table: string, name: string, unique: bool, fulltext: bool, columns: list<string>}>  $indexes */
    private function restoreIndexes(array $indexes): void
    {
        foreach ($indexes as $index) {
            $kind = $index['fulltext'] ? 'FULLTEXT INDEX' : ($index['unique'] ? 'UNIQUE INDEX' : 'INDEX');
            $columns = implode(', ', array_map(fn ($c) => "`{$c}`", $index['columns']));
            DB::statement("ALTER TABLE `{$index['table']}` ADD {$kind} `{$index['name']}` ({$columns})");
        }
    }

    /** @return array{0: list<int>, 1: list<int>, 2: int} */
    private function users(CarbonImmutable $now): array
    {
        $password = Hash::make('password');
        $row = fn (string $name, string $email, Role $role) => [
            'name' => $name, 'email' => $email, 'password' => $password, 'role' => $role->value,
            'email_verified_at' => $now, 'is_available' => true, 'created_at' => $now, 'updated_at' => $now,
        ];

        DB::table('users')->insertOrIgnore($row('Perf Admin', 'perf-admin@example.com', Role::Admin));

        $agents = [];
        foreach (range(1, 40) as $i) {
            $agents[] = $row("Agent {$i}", "perf-agent{$i}@example.com", Role::Agent) + ['last_assigned_at' => $now->subMinutes($i)];
        }
        DB::table('users')->insertOrIgnore($agents);

        foreach (array_chunk(range(1, 5000), self::CHUNK) as $chunk) {
            DB::table('users')->insertOrIgnore(array_map(fn ($i) => $row("Customer {$i}", "perf-customer{$i}@example.com", Role::Customer), $chunk));
        }

        return [
            DB::table('users')->where('email', 'like', 'perf-customer%')->pluck('id')->all(),
            DB::table('users')->where('email', 'like', 'perf-agent%')->pluck('id')->all(),
            (int) DB::table('users')->where('email', 'perf-admin@example.com')->value('id'),
        ];
    }

    private function tickets(int $count, CarbonImmutable $now, array $customers, array $agents, array $categories): void
    {
        $sla = ['low' => 2880, 'medium' => 1440, 'high' => 480, 'critical' => 120];
        $seq = [];
        $batch = [];

        for ($i = 1; $i <= $count; $i++) {
            $created = $now->subMinutes(mt_rand(10, 730 * 24 * 60));
            $priority = $this->pick([TicketPriority::Low->value => 40, TicketPriority::Medium->value => 35, TicketPriority::High->value => 18, TicketPriority::Critical->value => 7]);
            $finished = mt_rand(1, 100) <= 70 && $created->lt($now->subDays(2));
            $status = $finished
                ? (mt_rand(1, 100) <= 60 ? TicketStatus::Closed : TicketStatus::Resolved)
                : $this->pick([TicketStatus::Open->value => 30, TicketStatus::InProgress->value => 50, TicketStatus::WaitingForCustomer->value => 20], true);

            // Active tickets: most recent, a share in warning or breached.
            if (! $finished) {
                $created = $now->subMinutes(mt_rand(10, 5 * 24 * 60));
            }
            $minutes = $sla[$priority];
            $due = $created->addMinutes((int) ($minutes * 1.6)); // business-hours stretch, approximate
            $roll = mt_rand(1, 100);
            if (! $finished && $roll <= 8) {
                $due = $now->subMinutes(mt_rand(5, 600));
            } elseif (! $finished && $roll <= 18) {
                $due = $now->addMinutes(mt_rand(5, (int) ($minutes * 0.2) + 5));
            }
            $warning = $due->subMinutes((int) ($minutes * 0.25));

            $assigned = $status === TicketStatus::Open && mt_rand(1, 100) <= 70 ? null : $agents[array_rand($agents)];
            $resolvedAt = $finished ? $created->addMinutes(mt_rand(30, $minutes * 2)) : null;
            $year = $created->year;
            $seq[$year] = ($seq[$year] ?? 0) + 1;

            $batch[] = [
                'ticket_number' => sprintf('TKT-%d-%06d', $year, $seq[$year]),
                'subject' => $this->subject($i),
                'description' => $this->sentence(40),
                'priority' => $priority,
                'status' => $status->value,
                'category_id' => $categories[array_rand($categories)],
                'created_by' => $customers[array_rand($customers)],
                'assigned_agent_id' => $assigned,
                'assigned_at' => $assigned ? $created->addMinutes(mt_rand(1, 60)) : null,
                'sla_policy_id' => null,
                'sla_minutes' => $minutes,
                'sla_warning_at' => $warning,
                'due_at' => $due,
                'first_response_at' => $assigned ? $created->addMinutes(mt_rand(5, 120)) : null,
                'resolved_at' => $resolvedAt,
                'closed_at' => $status === TicketStatus::Closed ? $resolvedAt?->addDays(3) : null,
                'is_escalated' => ! $finished && $due->lt($now),
                'escalation_level' => ! $finished && $due->lt($now) ? 1 : 0,
                'warning_notified_at' => $warning->lt($now) ? $warning : null,
                'breached_notified_at' => ! $finished && $due->lt($now) ? $due : null,
                'external_reference' => null,
                'source' => TicketSource::Web->value,
                'created_at' => $created,
                'updated_at' => $resolvedAt ?? $created,
            ];

            if (count($batch) === self::CHUNK) {
                DB::transaction(fn () => DB::table('tickets')->insert($batch));
                $batch = [];
            }
        }

        if ($batch !== []) {
            DB::table('tickets')->insert($batch);
        }

        foreach ($seq as $year => $last) {
            DB::table('ticket_sequences')->upsert(['year' => $year, 'last_number' => $last], ['year'], ['last_number']);
        }
    }

    /**
     * Walks tickets in id order: one `ticket_created` activity per ticket, then
     * 0-6 replies (average 3) each with its activity, until $count replies
     * exist. Rows arrive in ticket order, so the (ticket_id, ...) indexes are
     * appended to rather than written at random.
     */
    private function replies(int $count, CarbonImmutable $now, array $customers, array $agents): void
    {
        $made = 0;
        $firstPass = true;
        $replies = $activities = [];

        while ($made < $count) {
            DB::table('tickets')->select(['id', 'created_by', 'created_at'])->orderBy('id')
                ->chunk(self::CHUNK, function ($tickets) use (&$made, &$replies, &$activities, $firstPass, $count, $now, $agents) {
                    foreach ($tickets as $ticket) {
                        $created = CarbonImmutable::parse($ticket->created_at);

                        if ($firstPass) {
                            $activities[] = [
                                'ticket_id' => $ticket->id, 'actor_id' => $ticket->created_by, 'type' => ActivityType::TicketCreated->value,
                                'description' => 'Ticket created', 'old_values' => null, 'new_values' => null, 'created_at' => $created,
                            ];
                        }

                        $span = max(1, (int) $created->diffInMinutes($now));
                        for ($n = $firstPass ? mt_rand(0, 6) : 1; $n > 0 && $made < $count; $n--, $made++) {
                            $internal = mt_rand(1, 100) <= 15;
                            $byCustomer = ! $internal && mt_rand(0, 1) === 1;
                            $author = $byCustomer ? $ticket->created_by : $agents[array_rand($agents)];
                            $at = $created->addMinutes(mt_rand(1, $span));

                            $replies[] = [
                                'ticket_id' => $ticket->id, 'user_id' => $author,
                                'type' => ($internal ? ReplyType::InternalNote : ReplyType::Public)->value,
                                'body' => $this->sentence(25), 'created_at' => $at, 'updated_at' => $at,
                            ];
                            $activities[] = [
                                'ticket_id' => $ticket->id, 'actor_id' => $author,
                                'type' => ($internal ? ActivityType::InternalNoteAdded : ($byCustomer ? ActivityType::CustomerReplied : ActivityType::AgentReplied))->value,
                                'description' => $internal ? 'Internal note added' : 'Reply added', 'old_values' => null, 'new_values' => null, 'created_at' => $at,
                            ];
                        }
                    }

                    $this->insertBatch($replies, $activities);
                    $replies = $activities = [];

                    return $firstPass || $made < $count;
                });

            $firstPass = false;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $replies
     * @param  list<array<string, mixed>>  $activities
     */
    private function insertBatch(array $replies, array $activities): void
    {
        DB::transaction(function () use ($replies, $activities): void {
            if ($replies !== []) {
                DB::table('ticket_replies')->insert($replies);
            }
            if ($activities !== []) {
                DB::table('ticket_activities')->insert($activities);
            }
        });
    }

    /** Attachment rows spread evenly over replies, generated in reply-id order. */
    private function attachments(int $count): void
    {
        $minReply = (int) DB::table('ticket_replies')->min('id');
        $maxReply = (int) DB::table('ticket_replies')->max('id');
        $step = max(1, intdiv($maxReply - $minReply + 1, $count));

        for ($offset = 0; $offset < $count; $offset += self::CHUNK) {
            $ids = [];
            for ($i = $offset; $i < min($count, $offset + self::CHUNK); $i++) {
                $ids[] = $minReply + $i * $step + mt_rand(0, $step - 1);
            }

            $replies = DB::table('ticket_replies')->whereIn('id', $ids)->orderBy('id')->get(['id', 'ticket_id', 'user_id', 'created_at']);

            DB::table('attachments')->insert($replies->map(function ($reply) {
                $uuid = (string) Str::uuid();

                return [
                    'ticket_id' => $reply->ticket_id, 'ticket_reply_id' => $reply->id, 'uploaded_by' => $reply->user_id,
                    'original_name' => "file-{$uuid}.pdf", 'stored_name' => "tickets/{$reply->ticket_id}/perf/{$uuid}.pdf",
                    'mime_type' => 'application/pdf', 'size_bytes' => mt_rand(1_000, 5_000_000),
                    'checksum' => hash('sha256', $uuid), 'disk' => 'private',
                    'created_at' => $reply->created_at, 'updated_at' => $reply->created_at,
                ];
            })->all());
        }
    }

    /** @param array<string|int, int> $weights */
    private function pick(array $weights, bool $asStatus = false): mixed
    {
        $roll = mt_rand(1, array_sum($weights));

        foreach ($weights as $value => $weight) {
            if (($roll -= $weight) <= 0) {
                return $asStatus ? TicketStatus::from($value) : $value;
            }
        }

        return array_key_first($weights);
    }

    private function subject(int $i): string
    {
        $topics = ['Printer', 'Invoice', 'Login', 'Password', 'Refund', 'Laptop', 'VPN', 'Email', 'Payment', 'Shipping', 'Account', 'Report'];
        $problems = ['not working', 'charged twice', 'keeps failing', 'is slow', 'missing', 'error on save', 'locked out', 'needs update'];

        return $topics[$i % count($topics)].' '.$problems[mt_rand(0, count($problems) - 1)]." #{$i}";
    }

    private function sentence(int $words): string
    {
        static $vocabulary = ['customer', 'ticket', 'printer', 'invoice', 'payment', 'refund', 'error', 'screen', 'login', 'password', 'network', 'office', 'laptop', 'please', 'urgent', 'again', 'since', 'yesterday', 'morning', 'update', 'account', 'server', 'email', 'report', 'billing', 'order', 'shipping', 'delay', 'broken', 'reset'];

        $out = [];
        for ($i = 0; $i < $words; $i++) {
            $out[] = $vocabulary[mt_rand(0, count($vocabulary) - 1)];
        }

        return ucfirst(implode(' ', $out)).'.';
    }

    private function info(string $message): void
    {
        $this->command->info(sprintf('[%5ds] %s', (int) (microtime(true) - LARAVEL_START), $message));
    }
}
