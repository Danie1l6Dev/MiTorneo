<?php

namespace App\Services;

use App\Enums\MatchParticipantSide;
use App\Enums\MatchParticipantSourceType;
use App\Enums\MatchStatus;
use App\Models\CompetitionPhase;
use App\Models\MatchParticipant;
use App\Models\Team;
use App\Models\TournamentMatch;
use Illuminate\Support\Collection;

class KnockoutBracketService
{
    /**
     * Build a full single-elimination bracket for a knockout-style phase: a
     * live draw randomly pairs every qualifier for round 1, and every round
     * after that, up to the final, is pre-created with its two sides left
     * pending -- each wired to "the winner of" a round-1 match via a
     * MatchParticipant -- so the bracket already exists end to end instead of
     * being built one round at a time.
     *
     * @param  Collection<int, Team>  $qualifiers
     */
    public function generateBracket(CompetitionPhase $phase, Collection $qualifiers): void
    {
        $pool = $qualifiers->all();
        shuffle($pool);

        $previousRound = collect();

        foreach (array_chunk($pool, 2) as [$home, $away]) {
            $previousRound->push($this->createMatch($phase, 1, $home->id, $away->id));
        }

        $roundNumber = 1;

        while ($previousRound->count() > 1) {
            $roundNumber++;
            $currentRound = collect();

            foreach ($previousRound->chunk(2) as $pair) {
                [$homeSource, $awaySource] = $pair->values()->all();

                $match = $this->createMatch($phase, $roundNumber, null, null);

                $this->createParticipant($match, MatchParticipantSide::Home, $homeSource);
                $this->createParticipant($match, MatchParticipantSide::Away, $awaySource);

                $currentRound->push($match);
            }

            $previousRound = $currentRound;
        }
    }

    /**
     * Propagate a just-finished match's winner into whichever pending match
     * has it wired as one of its sides, if any. A match with nothing
     * downstream (e.g. the final, or any league match) simply has no
     * MatchParticipant referencing it, so this is a no-op for those.
     */
    public function resolveWinner(TournamentMatch $finishedMatch): void
    {
        $participants = MatchParticipant::query()
            ->where('source_match_id', $finishedMatch->id)
            ->where('type', MatchParticipantSourceType::MatchWinner)
            ->get();

        if ($participants->isEmpty()) {
            return;
        }

        $winnerTeamId = $finishedMatch->home_score > $finishedMatch->away_score
            ? $finishedMatch->home_team_id
            : $finishedMatch->away_team_id;

        foreach ($participants as $participant) {
            $targetMatch = $participant->match;

            if ($participant->side === MatchParticipantSide::Home) {
                $targetMatch->home_team_id = $winnerTeamId;
            } else {
                $targetMatch->away_team_id = $winnerTeamId;
            }

            $targetMatch->save();
        }
    }

    private function createMatch(CompetitionPhase $phase, int $roundNumber, ?int $homeTeamId, ?int $awayTeamId): TournamentMatch
    {
        $match = new TournamentMatch;
        $match->tournament_id = $phase->tournament_id;
        $match->category_id = $phase->category_id;
        $match->competition_phase_id = $phase->id;
        $match->home_team_id = $homeTeamId;
        $match->away_team_id = $awayTeamId;
        $match->status = MatchStatus::Scheduled;
        $match->round_number = $roundNumber;
        $match->save();

        return $match;
    }

    private function createParticipant(TournamentMatch $match, MatchParticipantSide $side, TournamentMatch $sourceMatch): void
    {
        $participant = new MatchParticipant;
        $participant->match_id = $match->id;
        $participant->side = $side;
        $participant->type = MatchParticipantSourceType::MatchWinner;
        $participant->source_match_id = $sourceMatch->id;
        $participant->save();
    }
}
