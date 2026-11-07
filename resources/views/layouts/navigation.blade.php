{{--
    Sidebar. Rendered twice (desktop + mobile drawer); $unreadNotifications
    comes from the view composer in AppServiceProvider (one query per request).
--}}
@php($user = Auth::user())

<nav class="flex h-full w-full flex-col bg-ink-950 px-3 pb-3 pt-5" aria-label="{{ __('Main') }}">
    <a href="{{ route('dashboard') }}" class="mb-6 px-2">
        <x-brand :dark="true" />
    </a>

    @can('create', App\Models\Ticket::class)
        <a href="{{ route('tickets.create') }}" class="btn btn-primary mb-6 w-full">
            <x-icon name="plus" class="h-4 w-4" />
            {{ __('New ticket') }}
        </a>
    @endcan

    <div class="flex-1 space-y-6 overflow-y-auto">
        <div class="space-y-1">
            <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-ink-500">{{ __('Workspace') }}</p>
            @can('view-dashboard')
                <x-nav-link :href="route('staff.dashboard')" :active="request()->routeIs('staff.dashboard')" icon="chart">{{ __('Dashboard') }}</x-nav-link>
            @endcan
            <x-nav-link :href="route('tickets.index')" :active="request()->routeIs('tickets.*')" icon="ticket">
                @staff {{ __('Tickets') }} @else {{ __('My tickets') }} @endstaff
            </x-nav-link>
            @can('view-unassigned-queue')
                <x-nav-link :href="route('staff.unassigned')" :active="request()->routeIs('staff.unassigned')" icon="inbox">{{ __('Unassigned queue') }}</x-nav-link>
            @endcan
            <a href="{{ route('notifications.index') }}"
               @class([
                   'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium',
                   'bg-white/10 text-white' => request()->routeIs('notifications.*'),
                   'text-ink-300 hover:bg-white/5 hover:text-white' => ! request()->routeIs('notifications.*'),
               ])
               aria-label="{{ trans_choice('{0} Notifications|{1} 1 unread notification|[2,*] :count unread notifications', $unreadNotifications, ['count' => $unreadNotifications]) }}">
                <x-icon name="bell" @class(['h-5 w-5 shrink-0', 'text-brand-300' => request()->routeIs('notifications.*'), 'text-ink-400 group-hover:text-ink-200' => ! request()->routeIs('notifications.*')]) />
                <span class="flex-1">{{ __('Notifications') }}</span>
                @if ($unreadNotifications > 0)
                    <span data-testid="unread-count" class="rounded-full bg-rose-500 px-2 py-0.5 text-xs font-semibold text-white">
                        {{ $unreadNotifications > 99 ? '99+' : $unreadNotifications }}
                    </span>
                @endif
            </a>
        </div>

        @if ($user->can('manage-sla-settings') || $user->can('import', App\Models\Ticket::class))
            <div class="space-y-1">
                <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-ink-500">{{ __('Administration') }}</p>
                @can('manage-sla-settings')
                    <x-nav-link :href="route('admin.sla-settings.index')" :active="request()->routeIs('admin.sla-settings.*')" icon="adjustments">{{ __('SLA settings') }}</x-nav-link>
                @endcan
                @can('import', App\Models\Ticket::class)
                    <x-nav-link :href="route('admin.imports.index')" :active="request()->routeIs('admin.imports.*')" icon="upload">{{ __('Import') }}</x-nav-link>
                @endcan
            </div>
        @endif
    </div>

    {{-- Account --}}
    <div class="mt-4 border-t border-white/10 pt-3">
        <x-dropdown align="full" width="full" direction="up">
            <x-slot name="trigger">
                <button type="button" class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left hover:bg-white/5">
                    <x-avatar :name="$user->name" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-white">{{ $user->name }}</span>
                        <span class="block truncate text-xs text-ink-400">{{ $user->role->label() }}</span>
                    </span>
                    <x-icon name="chevron-up-down" class="h-4 w-4 text-ink-400" />
                </button>
            </x-slot>

            <x-slot name="content">
                <div class="border-b border-ink-100 px-2.5 pb-2 pt-1">
                    <p class="truncate text-xs text-ink-500">{{ $user->email }}</p>
                </div>
                <div class="pt-1">
                    <x-dropdown-link :href="route('profile.edit')" icon="user">{{ __('Profile') }}</x-dropdown-link>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm text-ink-700 hover:bg-ink-50 hover:text-ink-900">
                            <x-icon name="logout" class="h-4 w-4 text-ink-400" />
                            {{ __('Log Out') }}
                        </button>
                    </form>
                </div>
            </x-slot>
        </x-dropdown>
    </div>
</nav>
