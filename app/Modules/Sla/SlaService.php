<?php

namespace App\Modules\Sla;

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Activity\Models\TicketActivity;
use App\Modules\Assignment\AutoAssignTicket;
use App\Modules\Notifications\TicketNotifier;
use App\Modules\Sla\Enums\EscalationKind;
use App\Modules\Sla\Models\BusinessHour;
use App\Modules\Sla\Models\Holiday;
use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SLA deadlines in four parts: rules (cached), deadlines, escalation and admin
 * settings. Registered as scoped, so the rules are read once per request or job.
 */
class SlaService
{
    public const CACHE_KEY = 'support:sla-rules';

    public const CACHE_TTL = 3600;

    private ?array $rules = null;

    private ?BusinessCalendar $calendar = null;

    public function __construct(private readonly TicketNotifier $notifier) {}

    // --- 1. Rules ---

    /** Target for a priority; config/support.php when there is no active row. */
    public function policyFor(TicketPriority $priority): array
    {
        $row = $this->rules()['policies'][$priority->value] ?? null;

        if ($row === null || ! $row['is_active']) {
            return [
                'id' => null,
                'minutes' => $priority->defaultSlaMinutes(),
                'warning_threshold' => (float) config('support.sla.warning_threshold'),
            ];
        }

        return ['id' => $row['id'], 'minutes' => $row['minutes'], 'warning_threshold' => $row['warning_threshold']];
    }

    public function calendar(): BusinessCalendar
    {
        return $this->calendar ??= new BusinessCalendar(
            $this->rules()['hours'],
            $this->rules()['holidays'],
            (string) config('support.timezone'),
        );
    }

    public function flushRules(): void
    {
        Cache::forget(self::CACHE_KEY);

        $this->rules = null;
        $this->calendar = null;
    }

    /** Called by the rule models on save or delete: clears the cache after the commit. */
    public static function forgetRulesAfterCommit(): void
    {
        DB::afterCommit(fn () => app(self::class)->flushRules());
    }

    private function rules(): array
    {
        return $this->rules ??= Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn (): array => $this->loadRules());
    }

    private function loadRules(): array
    {
        $policies = SlaPolicy::query()
            ->get(['id', 'priority', 'resolution_minutes', 'warning_threshold', 'is_active'])
            ->mapWithKeys(fn (SlaPolicy $policy) => [$policy->priority->value => [
                'id' => $policy->id,
                'minutes' => $policy->resolution_minutes,
                'warning_threshold' => $policy->warning_threshold,
                'is_active' => $policy->is_active,
            ]])
            ->all();

        $rows = BusinessHour::query()->orderBy('weekday')->get(['weekday', 'is_working_day', 'opens_at', 'closes_at']);

        // No rows yet: the hours from config/support.php.
        $hours = $rows->isEmpty()
            ? collect(range(0, 6))->mapWithKeys(fn (int $weekday) => [$weekday => in_array($weekday, config('support.business_hours.days'), true)
                ? [config('support.business_hours.start'), config('support.business_hours.end')]
                : null,
            ])->all()
            : $rows->mapWithKeys(fn (BusinessHour $day) => [$day->weekday => $day->is_working_day && $day->opens_at && $day->closes_at
                ? [substr($day->opens_at, 0, 5), substr($day->closes_at, 0, 5)]
                : null,
            ])->all();

        // All holidays: a small table, and old tickets may be recalculated.
        $holidays = Holiday::query()->orderBy('date')->get(['date'])
            ->map(fn (Holiday $holiday) => $holiday->date->format('Y-m-d'))
            ->all();

        return ['policies' => $policies, 'hours' => $hours, 'holidays' => $holidays];
    }

    // --- 2. Deadlines ---

    /** The SLA columns for a ticket of $priority whose clock starts at $from. */
    public function calculate(TicketPriority $priority, CarbonInterface $from): array
    {
        return self::deadlines($this->calendar(), $this->policyFor($priority), $from);
    }

    /** The pure part of calculate(). Warning after round(minutes x threshold) business minutes; both in UTC. */
    public static function deadlines(BusinessCalendar $calendar, array $policy, CarbonInterface $from): array
    {
        $start = CarbonImmutable::instance($from);
        $warningMinutes = (int) round($policy['minutes'] * $policy['warning_threshold']);

        return [
            'sla_policy_id' => $policy['id'],
            'sla_minutes' => $policy['minutes'],
            'sla_warning_at' => $calendar->addBusinessMinutes($start, $warningMinutes)->utc(),
            'due_at' => $calendar->addBusinessMinutes($start, $policy['minutes'])->utc(),
        ];
    }

    /** The new SLA columns counted from $from, or null when they are unchanged. */
    public function changedDeadlines(Ticket $ticket, CarbonInterface $from): ?array
    {
        $new = $this->calculate($ticket->priority, $from);

        $unchanged = $ticket->sla_minutes === $new['sla_minutes']
            && $ticket->sla_policy_id === $new['sla_policy_id']
            && $ticket->due_at?->equalTo($new['due_at'])
            && $ticket->sla_warning_at?->equalTo($new['sla_warning_at']);

        return $unchanged ? null : $new;
    }

    /** New deadlines from $from, logged in the timeline; the caller saves. False when nothing changed. */
    public function recalculate(Ticket $ticket, CarbonInterface $from, ?User $actor, string $reason): bool
    {
        $new = $this->changedDeadlines($ticket, $from);

        if ($new === null) {
            return false;
        }

        $oldDueAt = $ticket->due_at;
        $old = [
            'due_at' => $oldDueAt?->toIso8601String(),
            'sla_warning_at' => $ticket->sla_warning_at?->toIso8601String(),
            'sla_minutes' => $ticket->sla_minutes,
        ];

        $ticket->fill($new);

        // A deadline in the future can warn and breach again.
        if ($new['due_at']->isFuture()) {
            $ticket->fill(['warning_notified_at' => null, 'breached_notified_at' => null]);
        }

        TicketActivity::record(
            $ticket,
            ActivityType::SlaRecalculated,
            $actor,
            sprintf('SLA recalculated (%s): due %s → %s', $reason, BusinessTime::format($oldDueAt), BusinessTime::format($new['due_at'])),
            $old,
            [
                'due_at' => $new['due_at']->toIso8601String(),
                'sla_warning_at' => $new['sla_warning_at']->toIso8601String(),
                'sla_minutes' => $new['sla_minutes'],
            ],
        );

        return true;
    }

    /**
     * sla:recalculate --open: re-applies the current rules to active tickets from
     * their created_at. Finished tickets keep their deadlines.
     */
    public function recalculateActiveTickets(bool $dryRun, int $chunk = 200): array
    {
        $checked = 0;
        $changed = 0;

        Ticket::query()
            ->active()
            ->select(['id', 'priority', 'status', 'created_at', 'sla_policy_id', 'sla_minutes', 'sla_warning_at', 'due_at'])
            ->chunkById(max(1, $chunk), function ($tickets) use ($dryRun, &$checked, &$changed): void {
                foreach ($tickets as $ticket) {
                    $checked++;

                    if ($dryRun) {
                        $changed += $this->changedDeadlines($ticket, $ticket->created_at) === null ? 0 : 1;

                        continue;
                    }

                    // One short transaction per ticket: lock, re-check, save.
                    $changed += DB::transaction(function () use ($ticket): int {
                        $locked = Ticket::query()->lockForUpdate()->find($ticket->id);

                        if ($locked === null || ! $locked->isActive() || ! $this->recalculate($locked, $locked->created_at, null, 'rules re-applied')) {
                            return 0;
                        }

                        $locked->save();

                        return 1;
                    });
                }
            });

        return ['changed' => $changed, 'checked' => $checked];
    }

    // --- 3. Escalation ---

    /** sla:scan (every minute): queues one EscalateTicketJob per ticket due a warning, breach or level 2. */
    public function scan(int $chunk = 200): array
    {
        $level2Cutoff = now()->subMinutes((int) config('support.escalation.level2_after_minutes'));

        $passes = [
            EscalationKind::Warning->value => Ticket::query()->slaApproaching()->whereNull('warning_notified_at'),
            EscalationKind::Breach->value => Ticket::query()->slaBreached()->whereNull('breached_notified_at'),
            EscalationKind::LevelTwo->value => Ticket::query()
                ->whereIn('status', TicketStatus::activeValues())
                ->where('is_escalated', true)
                ->where('escalation_level', 1)
                ->where('due_at', '<=', $level2Cutoff),
        ];

        $counts = [];

        foreach ($passes as $kind => $query) {
            // No ORDER BY: it would make the database walk the primary key.
            $ids = $query->toBase()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();

            foreach ($ids->chunk(max(1, $chunk)) as $batch) {
                foreach ($batch as $id) {
                    EscalateTicketJob::dispatch($id, EscalationKind::from($kind));
                }
            }

            $counts[$kind] = $ids->count();
        }

        return $counts;
    }

    /**
     * Applies one escalation step at most once per deadline, with a guarded UPDATE:
     *   warning: SET warning_notified_at WHERE warning_notified_at IS NULL AND sla_warning_at <= now
     *   breach:  SET breached_notified_at, is_escalated, level 1 WHERE breached_notified_at IS NULL AND due_at < now
     *   level 2: SET escalation_level = 2 WHERE escalation_level = 1 AND due_at <= now - level2_after_minutes
     * (each also requires an active status). Timeline entries and notifications
     * follow only when the row changed, so repeats do nothing.
     */
    public function escalate(int $ticketId, EscalationKind $kind): bool
    {
        return DB::transaction(fn (): bool => match ($kind) {
            EscalationKind::Warning => $this->warn($ticketId),
            EscalationKind::Breach => $this->breach($ticketId),
            EscalationKind::LevelTwo => $this->levelTwo($ticketId),
        });
    }

    private function warn(int $ticketId): bool
    {
        $affected = $this->stillActive($ticketId)
            ->whereNull('warning_notified_at')
            ->where('sla_warning_at', '<=', now())
            ->update(['warning_notified_at' => now()]);

        if ($affected === 0) {
            return false;
        }

        $ticket = Ticket::query()->findOrFail($ticketId);

        TicketActivity::record($ticket, ActivityType::SlaWarning, null, 'SLA warning: due '.BusinessTime::format($ticket->due_at), [], [
            'due_at' => $ticket->due_at?->toIso8601String(),
        ]);

        $this->notifier->slaWarning($ticket);

        return true;
    }

    private function breach(int $ticketId): bool
    {
        $affected = $this->stillActive($ticketId)
            ->whereNull('breached_notified_at')
            ->where('due_at', '<', now())
            ->update([
                'breached_notified_at' => now(),
                'is_escalated' => true,
                'escalation_level' => DB::raw('CASE WHEN escalation_level < 1 THEN 1 ELSE escalation_level END'),
            ]);

        if ($affected === 0) {
            return false;
        }

        $ticket = Ticket::query()->findOrFail($ticketId);
        $due = BusinessTime::format($ticket->due_at);

        TicketActivity::record($ticket, ActivityType::SlaBreached, null, "SLA breached: was due {$due}", [], [
            'due_at' => $ticket->due_at?->toIso8601String(),
        ]);
        TicketActivity::record($ticket, ActivityType::Escalated, null, 'Escalated to level 1 (SLA breached)', [], [
            'escalation_level' => $ticket->escalation_level,
        ]);

        $this->notifier->slaBreached($ticket); // the assignee and every admin

        if ($ticket->assigned_agent_id === null) {
            // Unowned and overdue: try to assign it now.
            AutoAssignTicket::dispatch($ticket->id)->afterCommit();
        }

        return true;
    }

    private function levelTwo(int $ticketId): bool
    {
        $minutes = (int) config('support.escalation.level2_after_minutes');

        $affected = $this->stillActive($ticketId)
            ->where('is_escalated', true)
            ->where('escalation_level', 1)
            ->where('due_at', '<=', now()->subMinutes($minutes))
            ->update(['escalation_level' => 2]);

        if ($affected === 0) {
            return false;
        }

        $ticket = Ticket::query()->findOrFail($ticketId);

        TicketActivity::record($ticket, ActivityType::Escalated, null, "Escalated to level 2 (still open {$minutes} minutes after the deadline)", [
            'escalation_level' => 1,
        ], [
            'escalation_level' => 2,
        ]);

        $this->notifier->escalated($ticket, 2);

        return true;
    }

    /** This ticket, still active. */
    private function stillActive(int $ticketId): Builder
    {
        return Ticket::query()->whereKey($ticketId)->whereIn('status', TicketStatus::activeValues());
    }

    // --- 4. Admin settings (saved row by row, so the model hooks clear the cache) ---

    /** Saves the target per priority; only new tickets use it. */
    public function updatePolicies(User $admin, array $policies): array
    {
        $changes = DB::transaction(function () use ($policies): array {
            $changes = [];

            foreach ($policies as $priority => $values) {
                $policy = SlaPolicy::query()->firstOrNew(['priority' => TicketPriority::from($priority)]);

                $policy->fill([
                    'resolution_minutes' => (int) $values['resolution_minutes'],
                    'warning_threshold' => round((float) $values['warning_threshold'], 2),
                    'is_active' => (bool) $values['is_active'],
                ]);

                if (! $policy->exists || $policy->isDirty()) {
                    $changes[$priority] = $policy->getDirty();
                    $policy->save();
                }
            }

            return $changes;
        });

        if ($changes !== []) {
            Log::info('SLA policies updated.', ['admin_id' => $admin->id, 'changes' => $changes]);
        }

        return $changes;
    }

    /** Saves the weekly hours; days off store no times. */
    public function updateBusinessHours(User $admin, array $days): void
    {
        $changes = DB::transaction(function () use ($days): array {
            $changes = [];

            foreach ($days as $weekday => $values) {
                $working = (bool) $values['is_working_day'];
                $day = BusinessHour::query()->firstOrNew(['weekday' => (int) $weekday]);

                $day->fill([
                    'is_working_day' => $working,
                    'opens_at' => $working ? $values['opens_at'].':00' : null,
                    'closes_at' => $working ? $values['closes_at'].':00' : null,
                ]);

                if (! $day->exists || $day->isDirty()) {
                    $changes[$weekday] = $day->getDirty();
                    $day->save();
                }
            }

            return $changes;
        });

        if ($changes !== []) {
            Log::info('Business hours updated.', ['admin_id' => $admin->id, 'changes' => $changes]);
        }
    }

    public function addHoliday(User $admin, array $data): Holiday
    {
        $holiday = Holiday::query()->create(['date' => $data['date'], 'name' => $data['name']]);

        Log::info('Holiday added.', ['admin_id' => $admin->id, 'holiday_id' => $holiday->id, 'date' => $data['date']]);

        return $holiday;
    }

    public function removeHoliday(User $admin, Holiday $holiday): void
    {
        $holiday->delete();

        Log::info('Holiday removed.', ['admin_id' => $admin->id, 'holiday_id' => $holiday->id, 'date' => $holiday->date->format('Y-m-d')]);
    }
}
