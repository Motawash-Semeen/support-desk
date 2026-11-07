@use('App\Support\BusinessTime')

<x-app-layout :title="$ticket->ticket_number.' · '.$ticket->subject">
    <x-slot name="header">
        <a href="{{ route('tickets.index') }}" class="inline-flex items-center gap-1 text-sm font-medium text-ink-500 hover:text-ink-800">
            <x-icon name="arrow-left" class="h-4 w-4" /> {{ __('Tickets') }}
        </a>
        <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="ticket-no">{{ $ticket->ticket_number }}</span>
                    <x-badge :color="$ticket->status->color()">{{ $ticket->status->label() }}</x-badge>
                    <x-badge :color="$ticket->priority->color()">{{ $ticket->priority->label() }}</x-badge>
                    <x-sla-badge :ticket="$ticket" />
                    @if ($ticket->is_escalated)
                        @staff
                            <x-badge color="red">{{ __('Escalated · level :level', ['level' => $ticket->escalation_level]) }}</x-badge>
                        @endstaff
                    @endif
                </div>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-ink-900">{{ $ticket->subject }}</h1>
                <p class="mt-1 text-sm text-ink-500">
                    {{ __('Opened by :name on :date', ['name' => $ticket->creator->name, 'date' => BusinessTime::format($ticket->created_at)]) }}
                    · {{ $ticket->category->name }}
                </p>
            </div>
            @can('update', $ticket)
                <a href="{{ route('tickets.edit', $ticket) }}" class="btn btn-secondary">
                    <x-icon name="pencil" class="h-4 w-4" /> {{ __('Edit') }}
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Conversation --}}
        <div class="space-y-4 lg:col-span-2">
            <article class="card p-5">
                <header class="mb-3 flex items-center gap-3">
                    <x-avatar :name="$ticket->creator->name" />
                    <div class="text-sm">
                        <p class="font-semibold text-ink-900">{{ $ticket->creator->name }}</p>
                        <p class="text-ink-500">{{ BusinessTime::format($ticket->created_at) }}</p>
                    </div>
                </header>
                <div class="whitespace-pre-line text-[15px] leading-7 text-ink-800">{{ $ticket->description }}</div>

                @if ($attachments->isNotEmpty())
                    @include('Conversation::partials.attachments', ['ticket' => $ticket, 'files' => $attachments])
                @endif
            </article>

            <div class="flex items-center gap-3 pt-2">
                <h2 class="text-sm font-semibold text-ink-900">{{ __('Conversation') }}</h2>
                <span class="h-px flex-1 bg-ink-200"></span>
                <span class="text-xs text-ink-500">{{ trans_choice('{0} No replies|{1} 1 reply|[2,*] :count replies', $replies->count(), ['count' => $replies->count()]) }}</span>
            </div>

            <ol class="space-y-4">
                @forelse ($replies as $reply)
                    <li id="reply-{{ $reply->id }}">
                        <article @class([
                            'rounded-xl p-5 ring-1',
                            'bg-amber-50/70 ring-amber-200' => $reply->isInternal(),
                            'bg-white shadow-card ring-ink-200/70' => ! $reply->isInternal() && $reply->author->isStaff(),
                            'bg-brand-50/40 ring-brand-100' => ! $reply->isInternal() && ! $reply->author->isStaff(),
                        ])>
                            <header class="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1">
                                <x-avatar :name="$reply->author->name" size="sm" />
                                <span class="text-sm font-semibold text-ink-900">{{ $reply->author->name }}</span>
                                <span class="text-xs text-ink-500">{{ $reply->author->role->label() }} · {{ BusinessTime::format($reply->created_at) }}</span>
                                @if ($reply->isInternal())
                                    <x-badge color="amber" class="ml-auto">
                                        <x-icon name="lock" class="h-3 w-3" /> {{ __('Internal note') }}
                                    </x-badge>
                                @endif
                            </header>
                            <div class="whitespace-pre-line pl-10 text-sm leading-6 text-ink-800">{{ $reply->body }}</div>

                            @if ($reply->attachments->isNotEmpty())
                                <div class="pl-10">
                                    @include('Conversation::partials.attachments', ['ticket' => $ticket, 'files' => $reply->attachments])
                                </div>
                            @endif
                        </article>
                    </li>
                @empty
                    <li class="rounded-xl border border-dashed border-ink-200 px-5 py-8 text-center text-sm text-ink-500">{{ __('No replies yet.') }}</li>
                @endforelse
            </ol>

            @include('Conversation::partials.reply-form', ['ticket' => $ticket])
        </div>

        {{-- Sidebar --}}
        <aside class="space-y-4">
            <x-card :title="__('Details')">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-500">{{ __('Category') }}</dt>
                        <dd class="text-right font-medium text-ink-800">{{ $ticket->category->name }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-ink-500">{{ __('Agent') }}</dt>
                        <dd class="text-right font-medium text-ink-800">
                            @if ($ticket->assignee)
                                <span class="inline-flex items-center gap-2"><x-avatar :name="$ticket->assignee->name" size="sm" /> {{ $ticket->assignee->name }}</span>
                            @else
                                <span class="text-ink-400">{{ __('Unassigned') }}</span>
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-500">{{ __('Due') }}</dt>
                        <dd class="text-right">
                            <span class="font-medium text-ink-800">{{ BusinessTime::format($ticket->due_at) }}</span>
                            <span class="block text-xs text-ink-400">{{ BusinessTime::zone() }}</span>
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-500">{{ __('First response') }}</dt>
                        <dd class="text-right text-ink-800">{{ BusinessTime::format($ticket->first_response_at) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-500">{{ __('Resolved') }}</dt>
                        <dd class="text-right text-ink-800">{{ BusinessTime::format($ticket->resolved_at) }}</dd>
                    </div>
                </dl>
            </x-card>

            @include('Assignment::partials.assignment', ['ticket' => $ticket, 'agents' => $agents])
            @include('Tickets::partials.status-actions', ['ticket' => $ticket])

            <x-Activity::ticket-timeline :ticket="$ticket" :activities="$activities" :direction="$timelineDirection" />
        </aside>
    </div>
</x-app-layout>
