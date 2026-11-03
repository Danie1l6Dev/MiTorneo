<?php

namespace App\Services;

use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Models\MatchEvent;
use App\Models\TournamentMatch;
use Illuminate\Support\Collection;

/**
 * Fair-play ("juego limpio") penalty points, the FIFA way: a team's total is
 * the sum, over every player AND coach shown a card in a finished match, of
 * ONE penalty for that person in that match -- the heaviest sanction applies,
 * they never add up:
 *
 *  - one yellow card ............................ 1
 *  - a second yellow card (expulsion) ........... 3
 *  - a direct red card .......................... 4
 *  - a yellow card and then a direct red card ... 5
 *
 * Fewer points means better fair play; it only breaks a tie once every other
 * criterion (points, goal difference, goals for, head-to-head) leaves teams
 * level -- see StandingsService.
 *
 * Cards are read from match_events (never stored as a running total), so
 * editing or deleting an event is reflected immediately.
 */
class FairPlayService
{
    /**
     * Penalty points for ONE person's cards in ONE match.
     */
    public function penaltyFor(int $yellowCards, int $redCards): int
    {
        return match (true) {
            // Two yellows send the player off whether or not the red that
            // follows was also recorded.
            $yellowCards >= 2 => 3,
            $yellowCards === 1 && $redCards >= 1 => 5,
            $redCards >= 1 => 4,
            $yellowCards === 1 => 1,
            default => 0,
        };
    }

    /**
     * Total penalty points per team over the finished matches in $matches,
     * keyed by team id. A team with no cards is simply absent (read it as 0).
     *
     * @param  Collection<int, TournamentMatch>  $matches
     * @return array<int, int>
     */
    public function penaltiesByTeam(Collection $matches): array
    {
        $matchIds = $matches
            ->filter(fn (TournamentMatch $match): bool => $match->id !== null && $match->status === MatchStatus::Finished)
            ->pluck('id')
            ->all();

        if ($matchIds === []) {
            return [];
        }

        $cards = MatchEvent::query()
            ->whereIn('match_id', $matchIds)
            ->whereIn('type', [MatchEventType::YellowCard, MatchEventType::RedCard])
            ->get(['match_id', 'team_id', 'player_id', 'coach_id', 'type']);

        // One bucket per person per match.
        $byPerson = $cards->groupBy(fn (MatchEvent $event): string => $event->match_id.'|'.$event->team_id.'|'.($event->player_id !== null ? 'p'.$event->player_id : 'c'.$event->coach_id));

        $penalties = [];

        foreach ($byPerson as $events) {
            $teamId = $events->first()->team_id;
            $yellows = $events->where('type', MatchEventType::YellowCard)->count();
            $reds = $events->where('type', MatchEventType::RedCard)->count();

            $penalties[$teamId] = ($penalties[$teamId] ?? 0) + $this->penaltyFor($yellows, $reds);
        }

        return $penalties;
    }
}
