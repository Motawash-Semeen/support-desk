<?php

namespace App\Modules\Sla\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sla\Actions\AddHoliday;
use App\Modules\Sla\Actions\RemoveHoliday;
use App\Modules\Sla\Actions\UpdateBusinessHours;
use App\Modules\Sla\Actions\UpdateSlaPolicies;
use App\Modules\Sla\Http\Requests\StoreHolidayRequest;
use App\Modules\Sla\Http\Requests\UpdateBusinessHoursRequest;
use App\Modules\Sla\Http\Requests\UpdateSlaPoliciesRequest;
use App\Modules\Sla\Models\BusinessHour;
use App\Modules\Sla\Models\Holiday;
use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Tickets\Enums\TicketPriority;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin editor for SLA rules. This screen owns the three rule tables, so it
 * reads them directly; everything else reads through SlaPolicyRepository.
 * Saving flushes the rule cache (SlaRulesObserver). Existing tickets keep
 * their snapshot.
 */
class SlaSettingsController extends Controller
{
    public function index(): View
    {
        $this->authorize('manage-sla-settings');

        $policies = SlaPolicy::query()->get()->keyBy(fn (SlaPolicy $policy) => $policy->priority->value);
        $hours = BusinessHour::query()->get()->keyBy('weekday');
        $defaultDays = config('support.business_hours.days');

        return view('Sla::settings', [
            'policies' => collect(TicketPriority::cases())
                ->sortByDesc(fn (TicketPriority $p) => $p->sortWeight())
                ->map(fn (TicketPriority $p) => [
                    'priority' => $p,
                    'resolution_minutes' => $policies[$p->value]->resolution_minutes ?? $p->defaultSlaMinutes(),
                    'warning_threshold' => $policies[$p->value]->warning_threshold ?? (float) config('support.sla.warning_threshold'),
                    'is_active' => $policies[$p->value]->is_active ?? true,
                ]),
            'days' => collect(range(0, 6))->map(fn (int $weekday) => [
                'weekday' => $weekday,
                'is_working_day' => $hours[$weekday]->is_working_day ?? in_array($weekday, $defaultDays, true),
                'opens_at' => substr($hours[$weekday]->opens_at ?? config('support.business_hours.start'), 0, 5),
                'closes_at' => substr($hours[$weekday]->closes_at ?? config('support.business_hours.end'), 0, 5),
            ]),
            'holidays' => Holiday::query()->where('date', '>=', today()->subDays(30)->toDateString())->orderBy('date')->limit(100)->get(),
        ]);
    }

    public function updatePolicies(UpdateSlaPoliciesRequest $request, UpdateSlaPolicies $updatePolicies): RedirectResponse
    {
        $this->authorize('manage-sla-settings');

        $updatePolicies($request->user(), $request->validated('policies'));

        return back()->with('status', __('SLA targets saved. New tickets use them; run sla:recalculate --open to re-apply them to active tickets.'));
    }

    public function updateBusinessHours(UpdateBusinessHoursRequest $request, UpdateBusinessHours $updateHours): RedirectResponse
    {
        $this->authorize('manage-sla-settings');

        $updateHours($request->user(), $request->validated('days'));

        return back()->with('status', __('Business hours saved.'));
    }

    public function storeHoliday(StoreHolidayRequest $request, AddHoliday $addHoliday): RedirectResponse
    {
        $this->authorize('manage-sla-settings');

        $addHoliday($request->user(), $request->validated());

        return back()->with('status', __('Holiday added.'));
    }

    public function destroyHoliday(Request $request, Holiday $holiday, RemoveHoliday $removeHoliday): RedirectResponse
    {
        $this->authorize('manage-sla-settings');

        $removeHoliday($request->user(), $holiday);

        return back()->with('status', __('Holiday removed.'));
    }
}
