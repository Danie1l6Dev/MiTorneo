<?php

use App\Models\CompetitionPhase;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $phaseName = '';

    #[Locked]
    public Collection $matches;

    public int $revealedCount = 0;

    public bool $dismissed = false;

    public function mount(CompetitionPhase $phase): void
    {
        $this->phaseName = $phase->name;

        $this->matches = $phase->matches()
            ->where('round_number', 1)
            ->with(['homeTeam', 'awayTeam'])
            ->orderBy('id')
            ->get();
    }

    public function revealNext(): void
    {
        if ($this->revealedCount < $this->matches->count()) {
            $this->revealedCount++;
        }
    }

    public function dismiss(): void
    {
        $this->dismissed = true;
    }
}; ?>

@if (! $dismissed)
    <div
        class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-8 overflow-y-auto bg-zinc-950/90 p-6 backdrop-blur-xl"
        {!! $revealedCount < $matches->count() ? 'wire:poll.900ms="revealNext"' : '' !!}
    >
        <div class="animate-fade-in-up space-y-2 text-center">
            <div class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-4 py-1.5 text-xs font-semibold uppercase tracking-widest text-white/70">
                <flux:icon.sparkles variant="micro" class="size-3.5 text-accent-content animate-shimmer-pulse" />
                {{ __('Sorteo en vivo') }}
            </div>

            <flux:heading size="xl" class="text-3xl! text-white">{{ $phaseName }}</flux:heading>

            <flux:text class="text-white/60">
                {{ __(':revealed de :total cruces revelados', ['revealed' => min($revealedCount, $matches->count()), 'total' => $matches->count()]) }}
            </flux:text>
        </div>

        <div class="grid w-full max-w-lg gap-3">
            @foreach ($matches as $index => $match)
                @if ($index < $revealedCount)
                    <div wire:key="match-{{ $match->id }}" class="animate-reveal-pop flex items-center justify-between gap-3 rounded-xl border border-white/10 bg-white/5 p-4 glass-panel">
                        <div class="min-w-0 flex-1 text-right text-sm font-semibold truncate text-white">
                            {{ $match->homeTeam->name }}
                        </div>

                        <div class="shrink-0 rounded-lg bg-white/10 px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-accent-content">
                            {{ __('vs') }}
                        </div>

                        <div class="min-w-0 flex-1 text-left text-sm font-semibold truncate text-white">
                            {{ $match->awayTeam->name }}
                        </div>
                    </div>
                @else
                    <div wire:key="pending-{{ $match->id }}" class="flex items-center justify-center gap-3 rounded-xl border border-dashed border-white/15 bg-white/[0.03] p-4">
                        <div class="flex size-8 items-center justify-center rounded-full bg-white/10 text-sm font-bold text-white/40 animate-shimmer-pulse">?</div>
                        <div class="text-xs font-medium uppercase tracking-widest text-white/30">{{ __('Sorteando…') }}</div>
                        <div class="flex size-8 items-center justify-center rounded-full bg-white/10 text-sm font-bold text-white/40 animate-shimmer-pulse">?</div>
                    </div>
                @endif
            @endforeach
        </div>

        @if ($revealedCount >= $matches->count())
            <flux:button variant="primary" wire:click="dismiss" icon:trailing="arrow-right" class="animate-fade-in-up">
                {{ __('Ver fase') }}
            </flux:button>
        @endif
    </div>
@endif
