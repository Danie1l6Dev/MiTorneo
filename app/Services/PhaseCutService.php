<?php

namespace App\Services;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchStatus;
use App\Models\CompetitionPhase;
use App\Models\TournamentMatch;
use Illuminate\Support\Collection;

/**
 * Lets the organizer cut a league short ("recorte"): the matches that were
 * never played are cancelled on purpose so the phase counts as finished and
 * can be advanced from, with the standings as they stand. Every cut match is
 * flagged (`cut_off`) so it can be told apart from one cancelled for another
 * reason and the cut can be undone.
 */
class PhaseCutService
{
    public function __construct(private PhaseEligibilityService $eligibility) {}

    /**
     * Whether $phase's league still has matches to cut and nothing has been
     * built from it yet.
     */
    public function canCut(CompetitionPhase $phase): bool
    {
        return $phase->type === CompetitionPhaseType::League
            && ! $this->eligibility->isAlreadyResolved($phase)
            && $this->pendingMatches($phase)->isNotEmpty();
    }

    /**
     * Whether a previous cut on $phase can still be undone: it has cut matches
     * and, like cutting, nothing was built from the league in the meantime.
     */
    public function canRestore(CompetitionPhase $phase): bool
    {
        return $phase->type === CompetitionPhaseType::League
            && ! $this->eligibility->isAlreadyResolved($phase)
            && $phase->matches()->where('cut_off', true)->exists();
    }

    /**
     * Cancel every match of $phase that hasn't been played.
     *
     * @return int how many matches were cut
     */
    public function cut(CompetitionPhase $phase): int
    {
        $matches = $this->pendingMatches($phase);

        foreach ($matches as $match) {
            $match->status = MatchStatus::Cancelled;
            $match->cut_off = true;
            $match->save();
        }

        return $matches->count();
    }

    /**
     * Put back to Scheduled every match a previous cut cancelled.
     *
     * @return int how many matches were restored
     */
    public function restore(CompetitionPhase $phase): int
    {
        $matches = $phase->matches()->where('cut_off', true)->get();

        foreach ($matches as $match) {
            $match->status = MatchStatus::Scheduled;
            $match->cut_off = false;
            $match->save();
        }

        return $matches->count();
    }

    /**
     * @return Collection<int, TournamentMatch>
     */
    private function pendingMatches(CompetitionPhase $phase): Collection
    {
        return $phase->matches()
            ->whereIn('status', [MatchStatus::Scheduled, MatchStatus::Postponed])
            ->get();
    }
}
