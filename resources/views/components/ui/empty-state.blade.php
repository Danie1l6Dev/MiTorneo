@props([
    'icon' => 'inbox',
    'message' => null,
])

<div {{ $attributes->class('flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-zinc-300 px-6 py-10 text-center dark:border-white/15') }}>
    <div class="flex size-11 items-center justify-center rounded-full bg-zinc-100 text-zinc-400 dark:bg-white/5 dark:text-white/40">
        <flux:icon :icon="$icon" variant="outline" class="size-5" />
    </div>

    <flux:text class="max-w-sm text-zinc-500 dark:text-white/60">{{ $message ?? $slot }}</flux:text>

    @isset($action)
        <div class="mt-1">{{ $action }}</div>
    @endisset
</div>
