<?php

namespace App\Services;

use App\Enums\MatchStatus;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use Illuminate\Support\Collection;

/**
 * Expels a plantel (Team) from one specific tournament -- e.g. the
 * organizer removing a team for an off-field incident. Scoped to exactly
 * that (team, tournament) pair via the tournament_team pivot: it never
 * touches another category the same club fields, nor a different (or
 * later) tournament the same global team also enters. See
 * Team::isExpelledFrom()/expulsionReasonFor().
 *
 * Every match the team hasn't played yet in this tournament/category is
 * forced to a 0-3 loss for the expelled side (a "walkover" -- see
 * TournamentMatch::$is_walkover/$walkover_team_id) so the opponent gets
 * full points; a match already Finished is left untouched, since it was
 * played before the expulsion. If both sides of a match happen to be
 * expelled, neither can be credited a win, so the match is Cancelled
 * instead.
 */
class TeamExpulsionService
{
    public function expel(Team $team, Tournament $tournament, ?string $reason): void
    {
        $tournament->globalTeams()->syncWithoutDetaching([$team->id]);
        $tournament->globalTeams()->updateExistingPivot($team->id, [
            'expelled_at' => now(),
            'expulsion_reason' => $reason,
        ]);

        foreach ($this->unplayedMatches($team, $tournament) as $match) {
            $opponentId = $match->home_team_id === $team->id ? $match->away_team_id : $match->home_team_id;
            $opponent = Team::find($opponentId);

            if ($opponent !== null && $opponent->isExpelledFrom($tournament)) {
                $match->status = MatchStatus::Cancelled;
                $match->save();

                continue;
            }

            $match->status = MatchStatus::Finished;
            $match->home_score = $match->home_team_id === $team->id ? 0 : 3;
            $match->away_score = $match->away_team_id === $team->id ? 0 : 3;
            $match->is_walkover = true;
            $match->walkover_team_id = $team->id;
            $match->save();
        }
    }

    /**
     * Undoes expel(): clears the pivot's expulsion and restores every match
     * it touched back to Scheduled with no score. A match that was instead
     * Cancelled because BOTH sides were expelled is only restored once the
     * OTHER side isn't expelled anymore either -- otherwise it's correctly
     * still cancelled for that other team's own expulsion.
     */
    public function revert(Team $team, Tournament $tournament): void
    {
        $matches = TournamentMatch::query()
            ->where('tournament_id', $tournament->id)
            ->where('category_id', $team->category_id)
            ->where(fn ($query) => $query->where('home_team_id', $team->id)->orWhere('away_team_id', $team->id))
            ->where(fn ($query) => $query->where('walkover_team_id', $team->id)->orWhere('status', MatchStatus::Cancelled))
            ->get();

        foreach ($matches as $match) {
            $opponentId = $match->home_team_id === $team->id ? $match->away_team_id : $match->home_team_id;
            $opponent = Team::find($opponentId);

            if ($match->walkover_team_id !== $team->id && $opponent !== null && $opponent->isExpelledFrom($tournament)) {
                continue;
            }

            $match->status = MatchStatus::Scheduled;
            $match->home_score = null;
            $match->away_score = null;
            $match->is_walkover = false;
            $match->walkover_team_id = null;
            $match->save();
        }

        $tournament->globalTeams()->updateExistingPivot($team->id, [
            'expelled_at' => null,
            'expulsion_reason' => null,
        ]);
    }

    /**
     * @return Collection<int, TournamentMatch>
     */
    private function unplayedMatches(Team $team, Tournament $tournament): Collection
    {
        return TournamentMatch::query()
            ->where('tournament_id', $tournament->id)
            ->where('category_id', $team->category_id)
            ->where(fn ($query) => $query->where('home_team_id', $team->id)->orWhere('away_team_id', $team->id))
            ->whereNotNull('home_team_id')
            ->whereNotNull('away_team_id')
            ->whereNotIn('status', [MatchStatus::Finished, MatchStatus::Cancelled])
            ->get();
    }
}
