@props(['tabs' => []])

{{--
    Same pill styling as x-ui.section-tabs, but each tab is a real link with
    server-computed "active" state instead of an Alpine x-data toggle -- for
    a selector whose state must survive a reload, a shared link, or the
    back/forward buttons (query-string driven), which a client-only Alpine
    toggle can't do.
--}}
<div {{ $attributes->class('inline-flex flex-wrap gap-1 rounded-lg border border-zinc-200 bg-zinc-100/70 p-1 dark:border-white/10 dark:bg-white/5') }}>
    @foreach ($tabs as $tab)
        <a
            href="{{ $tab['href'] }}"
            wire:navigate
            @class([
                'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                'bg-white text-zinc-900 shadow-[0_0_0_1px_var(--color-accent)] dark:bg-white/10 dark:text-white' => $tab['active'] ?? false,
                'text-zinc-600 hover:bg-white hover:text-zinc-900 dark:text-white/70 dark:hover:bg-white/10 dark:hover:text-white' => ! ($tab['active'] ?? false),
            ])
        >
            @isset($tab['icon'])
                <flux:icon :icon="$tab['icon']" variant="micro" class="size-4" />
            @endisset

            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
