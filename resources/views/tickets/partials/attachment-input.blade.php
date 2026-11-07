{{-- The accept/size hints help users; the server-side rules are the control. --}}
<div>
    <x-input-label for="attachments" :value="__('Attachments (optional)')" />
    <input id="attachments" name="attachments[]" type="file" multiple
           accept="{{ collect(config('support.attachments.mimes'))->map(fn ($ext) => '.'.$ext)->implode(',') }}"
           class="mt-1 block w-full text-sm text-gray-700 file:mr-3 file:rounded file:border-0 file:bg-gray-100 file:px-3 file:py-1.5">
    <p class="mt-1 text-xs text-gray-500">
        {{ __('Up to :count files, :size each. Allowed: :types.', [
            'count' => config('support.attachments.max_files'),
            'size' => \App\Support\FileSize::human((int) config('support.attachments.max_kb') * 1024),
            'types' => implode(', ', config('support.attachments.mimes')),
        ]) }}
    </p>
    <x-input-error class="mt-2" :messages="$errors->get('attachments')" />
    @foreach ($errors->get('attachments.*') as $messages)
        <x-input-error class="mt-1" :messages="$messages" />
    @endforeach
</div>
