@props([
    'category',
])

@php
    $rangeText = match (true) {
        $category->birth_year_from && $category->birth_year_to => __(':from–:to', ['from' => $category->birth_year_from, 'to' => $category->birth_year_to]),
        (bool) $category->birth_year_to => __('hasta :to', ['to' => $category->birth_year_to]),
        default => null,
    };
@endphp

@if ($rangeText || $category->female_extra_birth_years)
    <span {{ $attributes->class('flex flex-wrap items-center gap-x-2 gap-y-0.5') }}>
        @if ($rangeText)
            <span class="text-xs text-zinc-400 dark:text-white/40">{{ __('Nacidos :range', ['range' => $rangeText]) }}</span>
        @endif

        @if ($category->female_extra_birth_years)
            <flux:badge size="sm" color="pink">{{ __('Mujeres +:years años', ['years' => $category->female_extra_birth_years]) }}</flux:badge>
        @endif
    </span>
@endif
