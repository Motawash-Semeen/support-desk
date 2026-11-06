<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessHour;
use App\Models\Holiday;
use App\Models\SlaPolicy;
use Illuminate\View\View;

/**
 * Read-only overview of SLA rules. Editing is added with the SLA prompt.
 */
class SlaSettingsController extends Controller
{
    public function index(): View
    {
        $this->authorize('manage-sla-settings');

        return view('admin.sla-settings.index', [
            'policies' => SlaPolicy::query()->get()->sortByDesc(fn (SlaPolicy $p) => $p->priority->sortWeight())->values(),
            'hours' => BusinessHour::query()->orderBy('weekday')->get(),
            'holidays' => Holiday::query()->where('date', '>=', today()->toDateString())->orderBy('date')->limit(50)->get(),
        ]);
    }
}
