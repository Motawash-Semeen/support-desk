{{--
    Shared ticket fields. $showPriority / $showCategory are false for users the
    policy does not allow to set them; the server prohibits those fields anyway.
--}}
@php
    $currentPriority = old('priority', isset($ticket) ? $ticket->priority->value : 'medium');
    $currentCategory = (string) old('category_id', isset($ticket) ? $ticket->category_id : '');
@endphp

<div class="space-y-5">
    <div>
        <x-input-label for="subject" :value="__('Subject')" />
        <x-text-input id="subject" name="subject" type="text" class="mt-1.5" maxlength="255" required
                      :value="old('subject', $ticket->subject ?? '')" placeholder="{{ __('A short summary, e.g. “Invoice charged twice”') }}" />
        <x-input-error class="mt-2" :messages="$errors->get('subject')" />
    </div>

    @if ($showPriority || $showCategory)
        <div class="grid gap-5 sm:grid-cols-2">
            @if ($showCategory)
                <div>
                    <x-input-label for="category_id" :value="__('Category')" />
                    <select id="category_id" name="category_id" required class="field-input mt-1.5">
                        <option value="" disabled @selected($currentCategory === '')>{{ __('Choose a category') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected($currentCategory === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error class="mt-2" :messages="$errors->get('category_id')" />
                </div>
            @endif

            @if ($showPriority)
                <fieldset>
                    <legend class="field-label">{{ __('Priority') }}</legend>
                    <div class="mt-1.5 grid grid-cols-4 gap-1 rounded-lg bg-ink-100 p-1">
                        @foreach ($priorities as $priority)
                            <label class="cursor-pointer">
                                <input type="radio" name="priority" value="{{ $priority->value }}" @checked($currentPriority === $priority->value) class="peer sr-only">
                                <span class="block rounded-md px-2 py-1.5 text-center text-sm font-medium text-ink-500 transition peer-checked:bg-white peer-checked:text-ink-900 peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500">{{ $priority->label() }}</span>
                            </label>
                        @endforeach
                    </div>
                    <x-input-error class="mt-2" :messages="$errors->get('priority')" />
                </fieldset>
            @endif
        </div>
    @endif

    <div>
        <x-input-label for="description" :value="__('Description')" />
        <textarea id="description" name="description" rows="8" maxlength="10000" required
                  placeholder="{{ __('What happened, what you expected, and any steps to reproduce it.') }}"
                  class="field-input mt-1.5 resize-y">{{ old('description', $ticket->description ?? '') }}</textarea>
        <x-input-error class="mt-2" :messages="$errors->get('description')" />
    </div>
</div>
