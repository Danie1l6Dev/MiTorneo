@props(['tabs' => []])

<div {{ $attributes->class('inline-flex flex-wrap gap-1 rounded-lg border border-zinc-200 bg-zinc-100/70 p-1 dark:border-white/10 dark:bg-white/5') }}>
    @foreach ($tabs as $tab)
        <a
            href="{{ $tab['href'] }}"
            class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-zinc-600 transition-colors hover:bg-white hover:text-zinc-900 dark:text-white/70 dark:hover:bg-white/10 dark:hover:text-white"
        >
            @isset($tab['icon'])
                <flux:icon :icon="$tab['icon']" variant="micro" class="size-4" />
            @endisset

            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
