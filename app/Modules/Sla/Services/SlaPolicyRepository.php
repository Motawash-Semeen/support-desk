<?php

namespace App\Modules\Sla\Services;

use App\Modules\Sla\Models\BusinessHour;
use App\Modules\Sla\Models\Holiday;
use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Tickets\Enums\TicketPriority;
use Illuminate\Support\Facades\Cache;

/**
 * The single reader of sla_policies, business_hours and holidays outside the
 * admin settings screen. Loads all three once, caches them as plain arrays
 * for an hour, and is flushed by SlaRulesObserver whenever a row changes.
 *
 * Fallbacks keep the app working on an empty database: a missing or inactive
 * policy uses config('support.sla'); no business_hours rows uses
 * config('support.business_hours').
 *
 * Bound as a scoped singleton: memoised per request / queued job, never
 * across jobs in a long-running worker.
 */
final class SlaPolicyRepository implements SlaRules
{
    public const CACHE_KEY = 'support:sla-rules';

    public const CACHE_TTL = 3600;

    /** @var array{policies: array<string, array{id: int, minutes: int, warning_threshold: float, is_active: bool}>, hours: array<int, array{0: string, 1: string}|null>, holidays: list<string>}|null */
    private ?array $rules = null;

    private ?BusinessCalendar $calendar = null;

    public function policyFor(TicketPriority $priority): SlaPolicyRule
    {
        $row = $this->rules()['policies'][$priority->value] ?? null;

        if ($row === null || ! $row['is_active']) {
            return new SlaPolicyRule(
                id: null,
                minutes: $priority->defaultSlaMinutes(),
                warningThreshold: (float) config('support.sla.warning_threshold'),
            );
        }

        return new SlaPolicyRule($row['id'], $row['minutes'], $row['warning_threshold']);
    }

    public function calendar(): BusinessCalendar
    {
        return $this->calendar ??= new BusinessCalendar(
            $this->rules()['hours'],
            $this->rules()['holidays'],
            (string) config('support.timezone'),
        );
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);

        $this->rules = null;
        $this->calendar = null;
    }

    private function rules(): array
    {
        return $this->rules ??= Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn (): array => $this->load());
    }

    private function load(): array
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

        $hours = $rows->isEmpty()
            ? self::hoursFromConfig()
            : $rows->mapWithKeys(fn (BusinessHour $day) => [$day->weekday => $day->is_working_day && $day->opens_at && $day->closes_at
                ? [substr($day->opens_at, 0, 5), substr($day->closes_at, 0, 5)]
                : null,
            ])->all();

        // All holidays: a small table (tens of rows per year), and old open
        // tickets may be recalculated from their original creation date.
        $holidays = Holiday::query()
            ->orderBy('date')
            ->get(['date'])
            ->map(fn (Holiday $holiday) => $holiday->date->format('Y-m-d'))
            ->all();

        return ['policies' => $policies, 'hours' => $hours, 'holidays' => $holidays];
    }

    /** @return array<int, array{0: string, 1: string}|null> */
    private static function hoursFromConfig(): array
    {
        $days = config('support.business_hours.days');
        $window = [config('support.business_hours.start'), config('support.business_hours.end')];

        return collect(range(0, 6))
            ->mapWithKeys(fn (int $weekday) => [$weekday => in_array($weekday, $days, true) ? $window : null])
            ->all();
    }
}
