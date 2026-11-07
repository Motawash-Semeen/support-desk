{{--
    Shared ticket fields. $showPriority / $showCategory are false for users the
    policy does not allow to set them; the server prohibits those fields anyway.
--}}
@php
    $currentPriority = old('priority', isset($ticket) ? $ticket->priority->value : 'medium');
    $currentCategory = (string) old('category_id', isset($ticket) ? $ticket->category_id : '');
@endphp

<div class="space-y-6">
    <div>
        <x-input-label for="subject" :value="__('Subject')" />
        <x-text-input id="subject" name="subject" type="text" class="mt-1 block w-full" maxlength="255" required
                      :value="old('subject', $ticket->subject ?? '')" />
        <x-input-error class="mt-2" :messages="$errors->get('subject')" />
    </div>

    <div>
        <x-input-label for="description" :value="__('Description')" />
        <textarea id="description" name="description" rows="8" maxlength="10000" required
                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('description', $ticket->description ?? '') }}</textarea>
        <x-input-error class="mt-2" :messages="$errors->get('description')" />
    </div>

    <div class="grid gap-6 sm:grid-cols-2">
        @if ($showPriority)
            <div>
                <x-input-label for="priority" :value="__('Priority')" />
                <select id="priority" name="priority" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    @foreach ($priorities as $priority)
                        <option value="{{ $priority->value }}" @selected($currentPriority === $priority->value)>{{ $priority->label() }}</option>
                    @endforeach
                </select>
                <x-input-error class="mt-2" :messages="$errors->get('priority')" />
            </div>
        @endif

        @if ($showCategory)
            <div>
                <x-input-label for="category_id" :value="__('Category')" />
                <select id="category_id" name="category_id" required class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    <option value="" disabled @selected($currentCategory === '')>{{ __('Choose a category') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected($currentCategory === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
                <x-input-error class="mt-2" :messages="$errors->get('category_id')" />
            </div>
        @endif
    </div>
</div>
