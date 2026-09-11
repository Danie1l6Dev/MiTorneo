@props([
    'action',
    'method' => 'DELETE',
    'heading',
    'description' => null,
    'confirmLabel' => null,
    'icon' => 'trash',
])

{{--
    Replaces the browser's native confirm() dialog for every destructive
    action in the app with one designed to match it -- a Flux modal instead
    of the OS-styled alert box. $slot is the trigger (whatever button/icon
    already existed there); clicking it opens the modal instead of directly
    submitting, and the real POST only ever happens from the "Eliminar"
    button inside it. flux:modal.trigger wraps $slot in a plain
    `contents`-display div and listens for any click inside it, so it works
    unchanged whether the trigger is a flux:button, a bare <button>, or one
    wrapped in a flux:tooltip.

    A random modal name per render means this can be dropped inside a
    @foreach loop (e.g. one per row in a list) without every row fighting
    over the same modal.
--}}
@php
    $modalName = 'confirm-delete-'.\Illuminate\Support\Str::random(10);
    $confirmLabel ??= __('Eliminar');
@endphp

<flux:modal.trigger name="{{ $modalName }}">
    {{ $slot }}
</flux:modal.trigger>

<flux:modal name="{{ $modalName }}" class="max-w-sm">
    <div class="space-y-5">
        <div class="flex items-start gap-4">
            <div class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-red-500/15 text-red-500">
                <flux:icon :icon="$icon" variant="outline" class="size-5" />
            </div>

            <div class="space-y-1 pt-1">
                <flux:heading size="lg">{{ $heading }}</flux:heading>

                @if ($description)
                    <flux:text class="text-zinc-500 dark:text-white/60">{{ $description }}</flux:text>
                @endif
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Cancelar') }}</flux:button>
            </flux:modal.close>

            <form method="POST" action="{{ $action }}">
                @csrf
                @if (strtoupper($method) !== 'POST')
                    @method($method)
                @endif

                @isset($fields)
                    {{ $fields }}
                @endisset

                <flux:button type="submit" variant="danger">{{ $confirmLabel }}</flux:button>
            </form>
        </div>
    </div>
</flux:modal>
