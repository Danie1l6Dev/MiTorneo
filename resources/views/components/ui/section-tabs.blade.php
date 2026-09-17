@props(['tabs' => [], 'model' => 'section'])

{{--
    flex-nowrap + overflow-x-auto instead of flex-wrap: on a narrow screen,
    6+ tabs (or two filter-pill rows stacked above a stats table) used to
    wrap into a cramped multi-row grid. A single scrollable row is the same
    pattern most mobile tab bars use, and costs nothing on desktop -- it
    only ever scrolls if the tabs don't already fit.
--}}
<div {{ $attributes->class('inline-flex max-w-full gap-1 overflow-x-auto rounded-lg border border-zinc-200 bg-zinc-100/70 p-1 [scrollbar-width:none] dark:border-white/10 dark:bg-white/5 [&::-webkit-scrollbar]:hidden') }}>
    @foreach ($tabs as $tab)
        {{--
            A tab with an 'href' is a real link (a full page load, e.g. a
            statistics view that needs a fresh server query) instead of an
            Alpine-only client-side toggle -- both share the exact same
            active/inactive styling, and $model's initial value already
            reflects the URL that was actually requested, so the href tabs
            still highlight correctly right after their own reload.
        --}}
        @if (isset($tab['href']))
            <a
                href="{{ $tab['href'] }}"
                :class="{{ $model }} === '{{ $tab['key'] }}' ? 'bg-white text-zinc-900 shadow-[0_0_0_1px_var(--color-accent)] dark:bg-white/10 dark:text-white' : 'text-zinc-600 hover:bg-white hover:text-zinc-900 dark:text-white/70 dark:hover:bg-white/10 dark:hover:text-white'"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap transition-colors"
            >
                @isset($tab['icon'])
                    <flux:icon :icon="$tab['icon']" variant="micro" class="size-4 shrink-0" />
                @endisset

                {{ $tab['label'] }}
            </a>
        @else
            <button
                type="button"
                @click="{{ $model }} = '{{ $tab['key'] }}'"
                :class="{{ $model }} === '{{ $tab['key'] }}' ? 'bg-white text-zinc-900 shadow-[0_0_0_1px_var(--color-accent)] dark:bg-white/10 dark:text-white' : 'text-zinc-600 hover:bg-white hover:text-zinc-900 dark:text-white/70 dark:hover:bg-white/10 dark:hover:text-white'"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap transition-colors"
            >
                @isset($tab['icon'])
                    <flux:icon :icon="$tab['icon']" variant="micro" class="size-4 shrink-0" />
                @endisset

                {{ $tab['label'] }}
            </button>
        @endif
    @endforeach
</div>
