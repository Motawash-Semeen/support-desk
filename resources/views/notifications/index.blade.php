@use('App\Support\BusinessTime')

<x-app-layout :title="__('Notifications')" :subtitle="__('Updates about tickets you follow.')" width="narrow">
    <x-slot name="actions">
        <form method="POST" action="{{ route('notifications.read-all') }}">
            @csrf
            <x-secondary-button type="submit"><x-icon name="check-circle" class="h-4 w-4" /> {{ __('Mark all as read') }}</x-secondary-button>
        </form>
    </x-slot>

    <div class="card overflow-hidden">
        @if ($notifications->isEmpty())
            <x-empty-state icon="bell" :title="__('No notifications yet.')" :description="__('We will let you know when something happens on your tickets.')" />
        @else
            <ul class="divide-y divide-ink-100">
                @foreach ($notifications as $notification)
                    @php($unread = $notification->read_at === null)
                    <li @class(['flex items-start gap-3 px-5 py-4', 'bg-brand-50/40' => $unread])>
                        <span @class(['mt-1.5 h-2 w-2 shrink-0 rounded-full', 'bg-brand-500' => $unread, 'bg-transparent' => ! $unread]) aria-hidden="true"></span>
                        <div class="min-w-0 flex-1">
                            <p @class(['text-sm', 'font-medium text-ink-900' => $unread, 'text-ink-600' => ! $unread])>{{ $notification->data['message'] ?? '' }}</p>
                            <p class="mt-0.5 text-xs text-ink-500">{{ BusinessTime::format($notification->created_at) }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                                @csrf
                                <input type="hidden" name="open" value="1">
                                <button type="submit" class="btn btn-ghost btn-sm text-brand-700">{{ __('Open') }}</button>
                            </form>
                            @if ($unread)
                                <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-ghost btn-sm">{{ __('Mark read') }}</button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="mt-4">{{ $notifications->links() }}</div>
</x-app-layout>
