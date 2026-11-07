<?php

namespace App\Http\Controllers;

use App\Actions\Assignment\SetAgentAvailability;
use App\Http\Requests\UpdateAvailabilityRequest;
use Illuminate\Http\RedirectResponse;

class AvailabilityController extends Controller
{
    public function __invoke(UpdateAvailabilityRequest $request, SetAgentAvailability $setAvailability): RedirectResponse
    {
        $this->authorize('update-availability');

        $setAvailability($request->user(), $request->boolean('is_available'));

        return back()->with('status', $request->boolean('is_available')
            ? __('You are available for new tickets.')
            : __('You will not receive auto-assigned tickets.'));
    }
}
