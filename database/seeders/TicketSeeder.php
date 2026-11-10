<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Assignment\AssignmentService;
use App\Modules\Assignment\AutoAssignTicket;
use App\Modules\Conversation\ConversationService;
use App\Modules\Conversation\Enums\ReplyType;
use App\Modules\Sla\BusinessCalendar;
use App\Modules\Sla\Enums\EscalationKind;
use App\Modules\Sla\EscalateTicketJob;
use App\Modules\Sla\SlaService;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * Sample tickets (data/tickets.php) created by replaying the real services in
 * time order: opened, picked up, answered, resolved, closed, with SLA warnings and
 * breaches when due. Needs the reference seeders and UserSeeder; skips if tickets exist.
 */
class TicketSeeder extends Seeder
{
    /** Where each ticket must end up, checked after planning its timeline. */
    public const FLOWS = ['new', 'stale', 'assigned', 'working', 'waiting', 'approaching', 'breached', 'resolved', 'late', 'closed', 'reopened'];

    private CarbonImmutable $clock;

    private array $ids = [];

    private array $users = [];

    public function __construct(private readonly SlaService $sla) {}

    public function run(): void
    {
        if (Ticket::query()->exists()) {
            $this->command->warn('Tickets already exist; sample tickets skipped.');

            return;
        }

        $previousNow = Carbon::getTestNow();
        $now = CarbonImmutable::now()->startOfMinute();
        $samples = require __DIR__.'/data/tickets.php';

        $this->users = User::query()->get()->keyBy('email')->all();
        $calendar = $this->sla->calendar();

        $events = [];
        foreach ($samples as $i => $sample) {
            array_push($events, ...$this->plan($i, $sample, $now, $calendar));
        }
        usort($events, fn (array $a, array $b) => [$a['at'], $a['seq']] <=> [$b['at'], $b['seq']]);

        // Notifications run inline; mail goes nowhere; escalateUntil() stands in for the scheduler.
        $config = [config('queue.default'), config('mail.default')];
        $queue = Queue::getFacadeRoot();
        config(['queue.default' => 'sync', 'mail.default' => 'array']);
        Queue::fake([AutoAssignTicket::class, EscalateTicketJob::class]);

        try {
            $this->clock = $events[0]['at'];

            foreach ($events as $event) {
                $this->escalateUntil($event['at']);
                $this->travelTo($event['at']);
                $this->apply($event, $samples[$event['ticket']]);
            }

            $this->escalateUntil($now);
        } finally {
            Carbon::setTestNow($previousNow);
            CarbonImmutable::setTestNow($previousNow);
            Queue::swap($queue);
            config(['queue.default' => $config[0], 'mail.default' => $config[1]]);
        }

        // People have read their older notifications.
        DB::table('notifications')
            ->where('created_at', '<', $now->subDays(2))
            ->update(['read_at' => DB::raw('created_at')]);
    }

    /* ------------------------------------------------------------ planning */

    /** Finds a start time so the whole timeline is in the past and ends in the flow's state. */
    private function plan(int $i, array $sample, CarbonImmutable $now, BusinessCalendar $calendar): array
    {
        if (! in_array($sample['flow'], self::FLOWS, true)) {
            throw new RuntimeException("Unknown flow [{$sample['flow']}] for sample ticket {$i}.");
        }

        $slaPriority = TicketPriority::from($sample['raise'] ?? $sample['priority']);
        $hint = $now->subMinutes((int) round($sample['ago'] * 60));

        // Try moments around the hint, alternating earlier and later (never in the future).
        for ($try = 0; $try < 6000; $try++) {
            $offset = intdiv($try + 1, 2) * 23 * ($try % 2 === 0 ? 1 : -1);
            $created = $hint->addMinutes($offset);
            if ($created->greaterThanOrEqualTo($now)) {
                continue;
            }

            $events = $this->timeline($i, $sample, $created, $calendar);

            if ($this->fits($sample['flow'], $events, $slaPriority, $now)) {
                return $events;
            }
        }

        throw new RuntimeException("Could not place sample ticket {$i} ({$sample['subject']}).");
    }

    private function timeline(int $i, array $sample, CarbonImmutable $created, BusinessCalendar $calendar): array
    {
        $flow = $sample['flow'];
        $n = 0;
        $gap = function (int $min, int $max) use ($i, &$n): int {
            return $min + crc32("{$i}:".$n++) % ($max - $min + 1);
        };
        // Agents work in business hours; customers write whenever they like.
        $work = fn (CarbonImmutable $from, int $minutes) => $calendar->addBusinessMinutes($from, $minutes)->utc();
        $wall = fn (CarbonImmutable $from, int $minutes) => $from->addMinutes($minutes);

        $events = [];
        $add = function (CarbonImmutable $at, string $type, array $payload = []) use ($i, &$events): CarbonImmutable {
            $events[] = ['at' => $at, 'seq' => $i * 100 + count($events), 'ticket' => $i, 'type' => $type, 'payload' => $payload];

            return $at;
        };

        $add($created, 'create');
        if (in_array($flow, ['new', 'stale'], true)) {
            return $events;
        }

        $at = $add($work($created, $gap(10, 75)), 'claim', ['self' => $gap(0, 1) === 1]);
        if ($flow === 'assigned') {
            return $events;
        }

        if (isset($sample['raise'])) {
            $at = $add($work($at, $gap(3, 15)), 'raise');
        }

        $at = $add($work($at, $gap(15, 90)), 'reply', ['from' => 'agent', 'text' => $sample['a1']]);
        if (isset($sample['note']) && $flow !== 'reopened') {
            $add($work($at, $gap(2, 10)), 'note');
        }

        if ($flow === 'waiting') {
            $add($work($at, 1), 'waiting');

            return $events;
        }

        if (in_array($flow, ['working', 'approaching', 'breached'], true)) {
            if (isset($sample['c1'])) {
                $at = $add($wall($at, $gap(40, 300)), 'reply', ['from' => 'customer', 'text' => $sample['c1']]);
            }
            if (isset($sample['reassign'])) {
                $add($work($at, $gap(20, 90)), 'reassign');
            }

            return $events;
        }

        // resolved, late, closed, reopened
        $at = $flow === 'late'
            ? $add($wall($at, $gap(900, 1800)), 'reply', ['from' => 'customer', 'text' => $sample['c1']])
            : $add($wall($at, $gap(30, 600)), 'reply', ['from' => 'customer', 'text' => $sample['c1']]);
        $at = $add($work($at, $gap(20, 180)), 'reply', ['from' => 'agent', 'text' => $sample['a2']]);
        $resolved = $add($wall($at, 2), 'resolve');

        if ($flow === 'closed') {
            // tickets:auto-close-resolved runs daily at 02:00 (business time zone).
            $tz = $calendar->timezone();
            $due = $resolved->addDays((int) config('support.auto_close_resolved_after_days'))->setTimezone($tz);
            $run = $due->setTime(2, 0);
            $add(($run->lessThan($due) ? $run->addDay() : $run)->utc(), 'close');
        }

        if ($flow === 'reopened') {
            $at = $add($wall($resolved, $gap(600, 1500)), 'reopen');
            $at = $add($wall($at, 1), 'reply', ['from' => 'customer', 'text' => $sample['c2']]);
            $add($work($at, $gap(15, 60)), 'note');
        }

        return $events;
    }

    private function fits(string $flow, array $events, TicketPriority $priority, CarbonImmutable $now): bool
    {
        $last = collect($events)->max('at');
        if ($last->greaterThanOrEqualTo($now->subMinutes(5))) {
            return false;
        }

        $sla = $this->sla->calculate($priority, $events[0]['at']);
        $at = fn (string $type) => collect($events)->firstWhere('type', $type)['at'] ?? null;
        $recentlyResolved = fn () => $at('resolve')->greaterThan($now->subDays((int) config('support.auto_close_resolved_after_days'))->addHours(2));

        return match ($flow) {
            'new' => $sla['due_at']->greaterThan($now),
            'stale' => $sla['due_at']->lessThan($now->subDay()),
            'assigned', 'working', 'waiting' => $sla['sla_warning_at']->greaterThan($now),
            'approaching' => $sla['sla_warning_at']->lessThanOrEqualTo($now) && $sla['due_at']->greaterThan($now->addMinutes(10)),
            'breached' => $sla['due_at']->lessThan($now->subHours(3)) && $sla['due_at']->greaterThan($now->subDays(3)),
            'resolved' => $recentlyResolved(),
            'late' => $recentlyResolved() && $at('resolve')->greaterThan($sla['due_at']),
            'closed' => true,
            'reopened' => $at('reopen')->greaterThan($now->subDays(4))
                && $this->sla->calculate($priority, $at('reopen'))['sla_warning_at']->greaterThan($now),
            default => throw new RuntimeException("Unknown flow [{$flow}]."),
        };
    }

    /* ------------------------------------------------------------ replaying */

    private function apply(array $event, array $sample): void
    {
        $customer = $this->user(UserSeeder::CUSTOMERS[$sample['customer']][1]);
        $admin = $this->user(UserSeeder::ADMIN[1]);
        $ticket = fn (): Ticket => Ticket::query()->findOrFail($this->ids[$event['ticket']]);
        $agent = fn (): User => $this->user((int) Ticket::query()->whereKey($this->ids[$event['ticket']])->value('assigned_agent_id'));
        $first = $event['type'] === 'claim' ? $this->agentFor($event['ticket'], $sample) : null;

        match ($event['type']) {
            'create' => $this->ids[$event['ticket']] = app(TicketService::class)->create($customer, [
                'subject' => $sample['subject'],
                'description' => $sample['description'],
                'priority' => $sample['priority'],
                'category_id' => Category::query()->where('slug', $sample['category'])->value('id'),
            ])->getKey(),
            // An agent accepting from the queue, or the auto-assigner (actor = system).
            'claim' => app(AssignmentService::class)->claim($ticket(), $first, $event['payload']['self'] ? $first : null),
            'raise' => app(TicketService::class)->update($admin, $ticket(), ['priority' => $sample['raise']]),
            'reply' => app(ConversationService::class)->addReply($event['payload']['from'] === 'agent' ? $agent() : $customer, $ticket(), $event['payload']['text'], ReplyType::Public),
            'note' => app(ConversationService::class)->addReply($agent(), $ticket(), $sample['note'], ReplyType::InternalNote),
            'waiting' => app(TicketService::class)->changeStatus($agent(), $ticket(), TicketStatus::WaitingForCustomer),
            'resolve' => app(TicketService::class)->changeStatus($agent(), $ticket(), TicketStatus::Resolved),
            'close' => app(TicketService::class)->changeStatus(null, $ticket(), TicketStatus::Closed),
            'reopen' => app(TicketService::class)->changeStatus($customer, $ticket(), TicketStatus::reopenTarget()),
            'reassign' => app(AssignmentService::class)->assign($admin, $ticket(), $this->user(UserSeeder::AGENTS[$sample['reassign']][1]), $agent()->getKey()),
            default => throw new RuntimeException("Unknown step [{$event['type']}]."),
        };

        $this->clock = $event['at'];
    }

    /** What sla:scan would have done up to $until, in time order across all tickets. */
    private function escalateUntil(CarbonImmutable $until): void
    {
        $levelTwo = (int) config('support.escalation.level2_after_minutes');
        $tried = [];

        while (true) {
            $next = null;
            $floor = $this->clock->addMinute();

            $active = Ticket::query()
                ->whereIn('status', TicketStatus::activeValues())
                ->get(['id', 'sla_warning_at', 'due_at', 'warning_notified_at', 'breached_notified_at', 'is_escalated', 'escalation_level']);

            foreach ($active as $t) {
                $due = $t->due_at->toImmutable();
                $candidates = [];

                if ($t->warning_notified_at === null && $t->breached_notified_at === null) {
                    $candidates[EscalationKind::Warning->value] = $floor->max($t->sla_warning_at->toImmutable());
                }
                if ($t->breached_notified_at === null) {
                    $candidates[EscalationKind::Breach->value] = $floor->max($due->addMinute());
                }
                if ($t->is_escalated && (int) $t->escalation_level === 1) {
                    $candidates[EscalationKind::LevelTwo->value] = $floor->max($due->addMinutes($levelTwo));
                }

                foreach ($candidates as $kind => $at) {
                    $key = "{$t->id}:{$kind}";
                    $tooLate = $kind === EscalationKind::Warning->value && $at->greaterThan($due);

                    if ($at->lessThanOrEqualTo($until) && ! $tooLate && ! isset($tried[$key]) && ($next === null || $at->lessThan($next[0]))) {
                        $next = [$at, $t->id, EscalationKind::from($kind), $key];
                    }
                }
            }

            if ($next === null) {
                return;
            }

            [$at, $id, $kind, $key] = $next;
            $tried[$key] = true;
            $this->travelTo($at);
            $this->sla->escalate($id, $kind);
            $this->clock = $at;
        }
    }

    /* ------------------------------------------------------------ helpers */

    /** The ticket's first agent: as given in the sample, otherwise a specialist in turn. */
    private function agentFor(int $i, array $sample): User
    {
        if (isset($sample['agent'])) {
            return $this->user(UserSeeder::AGENTS[$sample['agent']][1]);
        }

        $specialists = array_values(array_filter(
            UserSeeder::AGENTS,
            fn (array $agent) => $agent[3] && in_array($sample['category'], $agent[2], true),
        ));

        return $this->user($specialists[$i % count($specialists)][1]);
    }

    private function user(string|int $emailOrId): User
    {
        $user = is_int($emailOrId)
            ? collect($this->users)->first(fn (User $u) => $u->getKey() === $emailOrId)
            : ($this->users[$emailOrId] ?? null);

        if (! $user instanceof User) {
            throw new RuntimeException("Unknown user [{$emailOrId}]: run UserSeeder first.");
        }

        return $user;
    }

    private function travelTo(CarbonImmutable $at): void
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }
}
