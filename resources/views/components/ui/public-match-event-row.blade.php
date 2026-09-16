@props([
    'events', // Collection<MatchEvent>, all for ONE subject (player or coach) -- same accumulation x-ui.match-event-row uses.
])

{{--
    Read-only counterpart to x-ui.match-event-row: same accumulated "2x G, 1x
    A" summary, but never a delete button -- x-ui.match-event-row's own
    confirm-delete-form posts to events.destroy, an auth-only admin route,
    so the public portal (see App\Http\Controllers\Public\PublicMatchController)
    uses this instead.
--}}
@php
    $subjectLabel = $events->first()->subjectLabel();
    $eventsByType = $events->groupBy(fn ($event) => $event->type->value);
@endphp

<div class="flex items-center justify-between gap-3 px-4 py-2.5">
    <span class="min-w-0 truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $subjectLabel }}</span>

    <div class="flex shrink-0 flex-wrap items-center justify-end gap-x-3 gap-y-1">
        @foreach (\App\Enums\MatchEventType::cases() as $type)
            @continue(! $eventsByType->has($type->value))

            @php $typeEvents = $eventsByType->get($type->value); @endphp

            <div class="flex items-center gap-1">
                <x-dynamic-component :component="$type->icon()" class="size-3.5 shrink-0 {{ $type->iconColorClass() }}" />
                <span class="text-xs font-semibold tabular-nums text-zinc-600 dark:text-white/70">{{ $typeEvents->count() }}x {{ $type->shortLabel() }}</span>
            </div>
        @endforeach
    </div>
</div>
