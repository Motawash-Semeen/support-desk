@use('App\Support\BusinessTime')

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center gap-3">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                <span class="font-mono text-gray-500">{{ $ticket->ticket_number }}</span>
                {{ $ticket->subject }}
            </h2>
            <x-badge :color="$ticket->status->color()">{{ $ticket->status->label() }}</x-badge>
            <x-badge :color="$ticket->priority->color()">{{ $ticket->priority->label() }}</x-badge>
            <x-sla-badge :ticket="$ticket" />
            @can('update', $ticket)
                <a href="{{ route('tickets.edit', $ticket) }}" class="ms-auto text-sm text-indigo-600 hover:underline">{{ __('Edit') }}</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 space-y-6">
                <section class="bg-white shadow-sm sm:rounded-lg p-6">
                    <p class="text-sm text-gray-500 mb-3">
                        {{ __('Opened by :name on :date', ['name' => $ticket->creator->name, 'date' => BusinessTime::format($ticket->created_at)]) }}
                    </p>
                    <div class="prose max-w-none whitespace-pre-line text-gray-800">{{ $ticket->description }}</div>

                    @if ($attachments->isNotEmpty())
                        @include('tickets.partials.attachments', ['ticket' => $ticket, 'files' => $attachments])
                    @endif
                </section>

                <section class="space-y-4">
                    <h3 class="font-semibold text-gray-800">{{ __('Conversation') }}</h3>

                    @forelse ($replies as $reply)
                        <article id="reply-{{ $reply->id }}" @class([
                            'shadow-sm sm:rounded-lg p-5',
                            'bg-amber-50 border border-amber-200' => $reply->isInternal(),
                            'bg-white' => ! $reply->isInternal(),
                        ])>
                            <header class="flex items-center gap-2 text-sm text-gray-500 mb-2">
                                <span class="font-medium text-gray-800">{{ $reply->author->name }}</span>
                                <span>· {{ $reply->author->role->label() }}</span>
                                <span>· {{ BusinessTime::format($reply->created_at) }}</span>
                                @if ($reply->isInternal())
                                    <x-badge color="amber">{{ __('Internal note') }}</x-badge>
                                @endif
                            </header>
                            <div class="whitespace-pre-line text-gray-800">{{ $reply->body }}</div>

                            @if ($reply->attachments->isNotEmpty())
                                @include('tickets.partials.attachments', ['ticket' => $ticket, 'files' => $reply->attachments])
                            @endif
                        </article>
                    @empty
                        <p class="text-sm text-gray-500">{{ __('No replies yet.') }}</p>
                    @endforelse

                    @include('tickets.partials.reply-form', ['ticket' => $ticket])
                </section>
            </div>

            <aside class="space-y-6">
                @include('tickets.partials.assignment', ['ticket' => $ticket, 'agents' => $agents])
                @include('tickets.partials.status-actions', ['ticket' => $ticket])

                <section class="bg-white shadow-sm sm:rounded-lg p-6">
                    <dl class="grid grid-cols-3 gap-y-3 text-sm">
                        <dt class="text-gray-500">{{ __('Category') }}</dt>
                        <dd class="col-span-2">{{ $ticket->category->name }}</dd>

                        <dt class="text-gray-500">{{ __('Agent') }}</dt>
                        <dd class="col-span-2">{{ $ticket->assignee?->name ?? __('Unassigned') }}</dd>

                        <dt class="text-gray-500">{{ __('Due') }}</dt>
                        <dd class="col-span-2">{{ BusinessTime::format($ticket->due_at) }} <span class="text-gray-400">({{ BusinessTime::zone() }})</span></dd>

                        <dt class="text-gray-500">{{ __('First response') }}</dt>
                        <dd class="col-span-2">{{ BusinessTime::format($ticket->first_response_at) }}</dd>

                        <dt class="text-gray-500">{{ __('Resolved') }}</dt>
                        <dd class="col-span-2">{{ BusinessTime::format($ticket->resolved_at) }}</dd>
                    </dl>
                </section>

                <x-ticket-timeline :ticket="$ticket" :activities="$activities" :direction="$timelineDirection" />
            </aside>
        </div>
    </div>
</x-app-layout>
