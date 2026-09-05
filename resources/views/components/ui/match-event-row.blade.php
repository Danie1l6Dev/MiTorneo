@props([
    'events', // Collection<MatchEvent>, all for ONE subject (player or coach) -- this renders one accumulated row, never one row per stored record.
])

@php
    $subjectLabel = $events->first()->subjectLabel();
    $eventsByType = $events->groupBy(fn ($event) => $event->type->value);
@endphp

<div class="flex items-center justify-between gap-3 px-4 py-2.5">
    <span class="min-w-0 truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $subjectLabel }}</span>

    {{-- Iterated via MatchEventType::cases() (goal, assist, yellow, red) --
         not the group's own insertion order -- so "2x G, 1x A, 1x TA" always
         reads in the same natural stat order no matter which happened first. --}}
    <div class="flex shrink-0 flex-wrap items-center justify-end gap-x-3 gap-y-1">
        @foreach (\App\Enums\MatchEventType::cases() as $type)
            @continue(! $eventsByType->has($type->value))

            @php $typeEvents = $eventsByType->get($type->value); @endphp

            <div class="flex items-center gap-1">
                <x-dynamic-component :component="$type->icon()" class="size-3.5 shrink-0 {{ $type->iconColorClass() }}" />
                <span class="text-xs font-semibold tabular-nums text-zinc-600 dark:text-white/70">{{ $typeEvents->count() }}x {{ $type->shortLabel() }}</span>

                {{-- Deletes just ONE occurrence of this type -- any one, since
                     they're indistinguishable now that minute is unused --
                     same decrement-one-at-a-time idea as the pending tray's
                     own remove button, just against already-saved rows. --}}
                <form method="POST" action="{{ route('events.destroy', $typeEvents->first()) }}" onsubmit="return confirm('{{ __('¿Eliminar un evento de tipo :type de :subject?', ['type' => $type->label(), 'subject' => $subjectLabel]) }}')">
                    @csrf
                    @method('DELETE')
                    <flux:tooltip :content="__('Eliminar uno')">
                        <button
                            type="submit"
                            class="rounded px-1 py-0.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/10 dark:hover:text-white"
                        >✕</button>
                    </flux:tooltip>
                </form>
            </div>
        @endforeach
    </div>
</div>
