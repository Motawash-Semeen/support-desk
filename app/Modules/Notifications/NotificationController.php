<?php

namespace App\Modules\Notifications;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The user's in-app notifications. Every query goes through their own notifications(), so other ids are 404. */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('Notifications::index', [
            'notifications' => $request->user()->notifications()->latest()->paginate(20),
        ]);
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $found = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $found->forceFill(['read_at' => now()])->save();

        // "Open": go to the ticket or import by its stored id, never a stored URL.
        if ($request->boolean('open') && isset($found->data['ticket_number'])) {
            return redirect()->route('tickets.show', $found->data['ticket_number']);
        }

        if ($request->boolean('open') && isset($found->data['import_id'])) {
            return redirect()->route('admin.imports.show', (int) $found->data['import_id']);
        }

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('status', __('All notifications marked as read.'));
    }
}
