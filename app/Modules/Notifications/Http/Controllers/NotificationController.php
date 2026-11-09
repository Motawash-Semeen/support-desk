<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * In-app notifications (database channel). Every query goes through
 * $request->user()->notifications(), so a user can only ever read or mark
 * their own; another user's id is a 404.
 */
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
        $found->markAsRead();

        // "Open": rebuild the target from stored ids (never a stored URL: no open redirect).
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
