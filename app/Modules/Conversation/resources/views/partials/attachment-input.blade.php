{{-- The accept/size hints help users; the server-side rules are the control. --}}
<div>
    <x-input-label for="attachments" :value="__('Attachments (optional)')" />
    <div class="mt-1.5 rounded-lg border border-dashed border-ink-300 bg-ink-50/50 p-3">
        <input id="attachments" name="attachments[]" type="file" multiple
               accept="{{ collect(config('support.attachments.mimes'))->map(fn ($ext) => '.'.$ext)->implode(',') }}"
               class="field-file">
        <p class="field-hint">
            {{ __('Up to :count files, :size each. Allowed: :types.', [
                'count' => config('support.attachments.max_files'),
                'size' => \App\Support\FileSize::human((int) config('support.attachments.max_kb') * 1024),
                'types' => implode(', ', config('support.attachments.mimes')),
            ]) }}
        </p>
    </div>
    <x-input-error class="mt-2" :messages="$errors->get('attachments')" />
    @foreach ($errors->get('attachments.*') as $messages)
        <x-input-error class="mt-1" :messages="$messages" />
    @endforeach
</div>
