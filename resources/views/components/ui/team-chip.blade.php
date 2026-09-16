@props([
    'team',
    // When given, the chip links to that team's public page
    // (public.tournaments.teams.show) instead of team-row's admin-only
    // teams.show -- see App\Http\Controllers\Public\PublicTeamController.
    'tournament' => null,
    'expelled' => false,
])

{{--
    A read-only counterpart to x-ui.team-row: same visual chip, but never an
    edit button -- team-row's own edit link (teams.edit) is an auth-only
    admin route, so the public portal (see
    App\Http\Controllers\Public\PublicCategoryController) never exposes it.
    Optionally links to the team's own public page when $tournament is given.
--}}
@php
    $tag = $tournament ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if ($tournament) href="{{ route('public.tournaments.teams.show', [$tournament, $team]) }}" wire:navigate @endif
    {{ $attributes->class('flex items-center gap-3 px-4 py-3' . ($tournament ? ' hover:bg-zinc-50 dark:hover:bg-white/5' : '')) }}
>
    <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-accent-content/15 text-xs font-bold uppercase text-accent-content">
        {{ \Illuminate\Support\Str::substr($team->short_name ?: $team->name, 0, 2) }}
    </div>

    <div class="min-w-0">
        <div class="flex items-center gap-2">
            <div class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $team->name }}</div>

            @if ($expelled)
                <flux:badge size="sm" color="red">{{ __('Expulsado') }}</flux:badge>
            @endif
        </div>

        @if ($team->short_name)
            <div class="truncate text-xs text-zinc-500 dark:text-white/50">{{ $team->short_name }}</div>
        @endif
    </div>
</{{ $tag }}>
