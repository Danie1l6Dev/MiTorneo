<?php

namespace App\Services;

use App\Enums\MatchParticipantSide;
use App\Enums\MatchParticipantSourceType;
use App\Enums\MatchStatus;
use App\Enums\ScheduleFormat;
use App\Models\CompetitionPhase;
use App\Models\MatchParticipant;
use App\Models\Team;
use App\Models\TournamentMatch;
use Illuminate\Support\Collection;

class KnockoutBracketService
{
    /**
     * Build a full single-elimination bracket for a knockout-style phase: a
     * live draw pairs every qualifier for round 1 in the order given (index 0
     * vs 1, 2 vs 3, ...) -- the caller decides that order (random shuffle or
     * seeded by standings, see StandingsService::seedQualifiers) -- and every
     * round after that, up to the final, is pre-created with its two sides
     * left pending -- each wired to "the winner of" a round-1 cross via a
     * MatchParticipant -- so the bracket already exists end to end instead of
     * being built one round at a time.
     *
     * $phase->knockout_format decides how many matches each cross (round-1
     * pairing, or later-round pairing of two previous crosses) is played
     * over: a single match for ScheduleFormat::SingleRound (the previous,
     * still-default behavior), or two legs -- sides swapped, the second
     * linked back to the first -- for ScheduleFormat::HomeAndAway. Either
     * way, exactly one match per cross is "decisive" (the only match, or the
     * second leg) and is what feeds the next round / gets read by
     * resolveWinner().
     *
     * $phase->final_knockout_format, when set, overrides $knockout_format for
     * the final cross only -- the organizer can e.g. keep every earlier cross
     * to a single match but make the final itself ida y vuelta, or vice
     * versa. Null (the default) means the final follows the general format
     * like every other cross, exactly as before this option existed.
     *
     * $phase->plays_third_place, when true, additionally creates one extra
     * 3er/4to puesto match -- always a single match regardless of either
     * format above -- wired to "the loser of" each of the two crosses in the
     * round right before the final. Silently skipped for a 2-qualifier
     * bracket (the final IS round 1 there, so there's no earlier round to
     * draw two losers from) even if the flag is set.
     *
     * @param  Collection<int, Team>  $qualifiers
     */
    public function generateBracket(CompetitionPhase $phase, Collection $qualifiers): void
    {
        $format = $phase->knockout_format ?? ScheduleFormat::SingleRound;
        $finalFormat = $phase->final_knockout_format ?? $format;
        $pool = $qualifiers->all();

        // A 2-qualifier bracket's only cross IS the final -- round 1 and the
        // final are the same thing, so it must use $finalFormat too.
        $round1IsFinal = count($pool) === 2;

        $previousRound = collect();

        foreach (array_chunk($pool, 2) as [$home, $away]) {
            $previousRound->push($this->createCross($phase, 1, $home->id, $away->id, null, null, $round1IsFinal ? $finalFormat : $format));
        }

        $roundNumber = 1;
        // The two decisive matches of the round right before the final --
        // i.e. the semifinal crosses -- captured so a 3er/4to puesto match
        // can wire their losers as its own two sides. Stays null for a
        // 2-qualifier bracket, where no such round exists.
        $semifinalCrosses = null;

        while ($previousRound->count() > 1) {
            $roundNumber++;
            // This iteration is about to reduce $previousRound down to the
            // single final cross exactly when it currently holds 2 crosses --
            // i.e. $previousRound right now IS the semifinal round.
            $isFinalRound = $previousRound->count() === 2;

            if ($isFinalRound) {
                $semifinalCrosses = $previousRound->values()->all();
            }

            $currentRound = collect();

            foreach ($previousRound->chunk(2) as $pair) {
                [$homeSource, $awaySource] = $pair->values()->all();

                $currentRound->push($this->createCross($phase, $roundNumber, null, null, $homeSource, $awaySource, $isFinalRound ? $finalFormat : $format));
            }

            $previousRound = $currentRound;
        }

        if ($phase->plays_third_place && $semifinalCrosses !== null) {
            $this->createThirdPlaceMatch($phase, $roundNumber, $semifinalCrosses[0], $semifinalCrosses[1]);
        }
    }

    /**
     * The extra 3er/4to puesto cross: always a single match (see
     * generateBracket()'s docblock), sharing the final's own round_number
     * but flagged is_third_place so PhaseBoardService::bracketRounds() never
     * counts it as a second cross of that round -- that would both inflate
     * the round's match count (breaking its "Final" label, derived purely
     * from cross count) and corrupt championFromBracket()'s reading of the
     * final cross.
     */
    private function createThirdPlaceMatch(CompetitionPhase $phase, int $roundNumber, TournamentMatch $semifinalCrossA, TournamentMatch $semifinalCrossB): void
    {
        $match = $this->createMatch($phase, $roundNumber, null, null);
        $match->is_third_place = true;
        $match->save();

        $this->createParticipant($match, MatchParticipantSide::Home, $semifinalCrossA, MatchParticipantSourceType::MatchLoser);
        $this->createParticipant($match, MatchParticipantSide::Away, $semifinalCrossB, MatchParticipantSourceType::MatchLoser);
    }

    /**
     * Propagate a just-finished match's cross winner (and, for a semifinal
     * feeding a 3er/4to puesto match, its loser too) into whichever pending
     * match has that cross wired as one of its sides, if any. A cross with
     * nothing downstream (e.g. the final with no 3er/4to puesto match, or
     * any league match) simply has no MatchParticipant referencing its
     * decisive match, so this is a no-op for those -- and so is finishing a
     * two-legged cross's first leg, since only the decisive (second) leg is
     * ever wired as a source.
     */
    public function resolveWinner(TournamentMatch $finishedMatch): void
    {
        $participants = MatchParticipant::query()
            ->where('source_match_id', $finishedMatch->id)
            ->whereIn('type', [MatchParticipantSourceType::MatchWinner, MatchParticipantSourceType::MatchLoser])
            ->get();

        if ($participants->isEmpty()) {
            return;
        }

        $winnerTeamId = $finishedMatch->tieWinnerTeamId();

        if ($winnerTeamId === null) {
            return;
        }

        $loserTeamId = $finishedMatch->tieLoserTeamId();

        foreach ($participants as $participant) {
            $teamId = $participant->type === MatchParticipantSourceType::MatchLoser ? $loserTeamId : $winnerTeamId;

            if ($teamId === null) {
                continue;
            }

            $targetMatch = $participant->match;

            if ($participant->side === MatchParticipantSide::Home) {
                $targetMatch->home_team_id = $teamId;
            } else {
                $targetMatch->away_team_id = $teamId;
            }

            $targetMatch->save();
        }
    }

    /**
     * Create one cross (tie) of the bracket: a single match for
     * ScheduleFormat::SingleRound, or two -- a first leg and, sides
     * swapped, a second leg linked back to it via first_leg_match_id -- for
     * ScheduleFormat::HomeAndAway. Either the two teams are already known
     * (round 1: $homeTeamId/$awayTeamId given, no sources) or each is "the
     * winner of" an earlier cross ($homeSource/$awaySource, wired via
     * MatchParticipant exactly as before -- the second leg's sources are
     * the same two crosses, just assigned to the opposite sides). Returns
     * the match that decides the cross and so feeds the next round: the
     * only match for a single leg, the second leg otherwise.
     */
    private function createCross(
        CompetitionPhase $phase,
        int $roundNumber,
        ?int $homeTeamId,
        ?int $awayTeamId,
        ?TournamentMatch $homeSource,
        ?TournamentMatch $awaySource,
        ScheduleFormat $format,
    ): TournamentMatch {
        $firstLeg = $this->createMatch($phase, $roundNumber, $homeTeamId, $awayTeamId);

        if ($homeSource !== null) {
            $this->createParticipant($firstLeg, MatchParticipantSide::Home, $homeSource);
        }

        if ($awaySource !== null) {
            $this->createParticipant($firstLeg, MatchParticipantSide::Away, $awaySource);
        }

        if ($format === ScheduleFormat::SingleRound) {
            return $firstLeg;
        }

        $secondLeg = $this->createMatch($phase, $roundNumber, $awayTeamId, $homeTeamId);
        $secondLeg->first_leg_match_id = $firstLeg->id;
        $secondLeg->save();

        if ($awaySource !== null) {
            $this->createParticipant($secondLeg, MatchParticipantSide::Home, $awaySource);
        }

        if ($homeSource !== null) {
            $this->createParticipant($secondLeg, MatchParticipantSide::Away, $homeSource);
        }

        return $secondLeg;
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

    private function createParticipant(TournamentMatch $match, MatchParticipantSide $side, TournamentMatch $sourceMatch, MatchParticipantSourceType $type = MatchParticipantSourceType::MatchWinner): void
    {
        $participant = new MatchParticipant;
        $participant->match_id = $match->id;
        $participant->side = $side;
        $participant->type = $type;
        $participant->source_match_id = $sourceMatch->id;
        $participant->save();
    }
}
