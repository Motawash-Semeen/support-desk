@use('App\Support\BusinessTime')

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Notifications') }}</h2>
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <x-secondary-button type="submit">{{ __('Mark all as read') }}</x-secondary-button>
            </form>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <ul class="bg-white shadow-sm sm:rounded-lg divide-y divide-gray-100">
                @forelse ($notifications as $notification)
                    <li @class(['p-4 flex items-start justify-between gap-4', 'bg-indigo-50' => $notification->read_at === null])>
                        <div>
                            <p class="text-sm text-gray-800">{{ $notification->data['message'] ?? '' }}</p>
                            <p class="text-xs text-gray-500">{{ BusinessTime::format($notification->created_at) }}</p>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                                @csrf
                                <input type="hidden" name="open" value="1">
                                <button type="submit" class="text-sm text-indigo-600 hover:underline">{{ __('Open') }}</button>
                            </form>
                            @if ($notification->read_at === null)
                                <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                                    @csrf
                                    <button type="submit" class="text-sm text-gray-500 hover:underline">{{ __('Mark read') }}</button>
                                </form>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="p-6 text-center text-sm text-gray-500">{{ __('No notifications yet.') }}</li>
                @endforelse
            </ul>

            <div class="mt-4">{{ $notifications->links() }}</div>
        </div>
    </div>
</x-app-layout>
