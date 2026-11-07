@use('App\Support\BusinessTime')

<x-app-layout :title="__('Error').' #'.$error->id">
    <x-slot name="header">
        <a href="{{ route('admin.errors.index') }}" class="inline-flex items-center gap-1 text-sm font-medium text-ink-500 hover:text-ink-800">
            <x-icon name="arrow-left" class="h-4 w-4" /> {{ __('Error log') }}
        </a>
        <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="ticket-no">#{{ $error->id }}</span>
                    <x-badge :color="$error->levelColor()">{{ ucfirst($error->level) }}</x-badge>
                    <x-badge>{{ ucfirst($error->source) }}</x-badge>
                    @if ($error->isResolved())
                        <x-badge color="green">{{ __('Resolved') }}</x-badge>
                    @else
                        <x-badge color="red">{{ __('Open') }}</x-badge>
                    @endif
                </div>
                <h1 class="mt-2 break-words text-xl font-semibold tracking-tight text-ink-900">{{ $error->message }}</h1>
                @if ($error->exception_class)
                    <p class="mt-1 font-mono text-sm text-ink-500">{{ $error->exception_class }}</p>
                @endif
            </div>
            <div class="flex shrink-0 gap-2">
                <form method="POST" action="{{ route('admin.errors.resolve', $error) }}">
                    @csrf
                    @method('PATCH')
                    @if ($error->isResolved())
                        <x-secondary-button type="submit"><x-icon name="refresh" class="h-4 w-4" /> {{ __('Reopen') }}</x-secondary-button>
                    @else
                        <x-primary-button><x-icon name="check-circle" class="h-4 w-4" /> {{ __('Mark resolved') }}</x-primary-button>
                    @endif
                </form>
                <form method="POST" action="{{ route('admin.errors.destroy', $error) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-ghost text-rose-600 hover:bg-rose-50 hover:text-rose-700"><x-icon name="trash" class="h-4 w-4" /> {{ __('Delete') }}</button>
                </form>
            </div>
        </div>
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @if ($error->file)
                <x-card :title="__('Location')">
                    <p class="break-all font-mono text-sm text-ink-800">{{ $error->file }}<span class="text-brand-700">:{{ $error->line }}</span></p>
                </x-card>
            @endif

            @if ($error->trace)
                <x-card :title="__('Stack trace')" :padded="false">
                    <pre class="max-h-[32rem] overflow-auto rounded-b-xl bg-ink-950 p-5 font-mono text-xs leading-relaxed text-ink-200">{{ $error->trace }}</pre>
                </x-card>
            @endif

            @if ($error->context)
                <x-card :title="__('Context')" :description="__('Values whose name looks secret (passwords, tokens, cookies) are redacted before storage.')" :padded="false">
                    <pre class="max-h-96 overflow-auto rounded-b-xl bg-ink-50 p-5 font-mono text-xs leading-relaxed text-ink-800">{{ json_encode($error->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                </x-card>
            @endif
        </div>

        <aside class="space-y-4">
            <x-card :title="__('Occurrences')">
                <p class="text-3xl font-semibold tabular-nums text-ink-900">{{ number_format($error->occurrences) }}</p>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-500">{{ __('First seen') }}</dt>
                        <dd class="text-right text-ink-800">{{ BusinessTime::format($error->first_seen_at) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-500">{{ __('Last seen') }}</dt>
                        <dd class="text-right text-ink-800">{{ BusinessTime::format($error->last_seen_at) }} <span class="block text-xs text-ink-400">{{ $error->last_seen_at->diffForHumans() }}</span></dd>
                    </div>
                    @if ($error->isResolved())
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-500">{{ __('Resolved') }}</dt>
                            <dd class="text-right text-ink-800">{{ BusinessTime::format($error->resolved_at) }} <span class="block text-xs text-ink-400">{{ $error->resolver?->name }}</span></dd>
                        </div>
                    @endif
                </dl>
            </x-card>

            <x-card :title="__('Latest occurrence')">
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-ink-500">{{ __('Request') }}</dt>
                        <dd class="mt-0.5 break-all font-mono text-xs text-ink-800">
                            @if ($error->method)<span class="font-semibold">{{ $error->method }}</span>@endif
                            {{ $error->url ?? '—' }}
                        </dd>
                    </div>
                    @if ($error->status_code)
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-500">{{ __('Status') }}</dt>
                            <dd class="font-mono text-ink-800">{{ $error->status_code }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-500">{{ __('User') }}</dt>
                        <dd class="text-right text-ink-800">
                            @if ($error->user)
                                {{ $error->user->name }} <span class="block text-xs text-ink-400">{{ $error->user->email }}</span>
                            @else
                                <span class="text-ink-400">{{ __('Guest or system') }}</span>
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-500">{{ __('IP address') }}</dt>
                        <dd class="font-mono text-ink-800">{{ $error->ip ?? '—' }}</dd>
                    </div>
                </dl>
            </x-card>
        </aside>
    </div>
</x-app-layout>
