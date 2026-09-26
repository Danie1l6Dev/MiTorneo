<?php

namespace App\Services;

use App\Enums\MatchEventType;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Sanction;
use App\Models\Team;
use App\Models\Tournament;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What the "Buscar jugador" section shows: the catalog its live search filters,
 * and one player's whole record (ficha).
 *
 * The record is built only from what the system really stores. In particular
 * there is NO history of which planteles/clubs a player belonged to and when:
 * moving a player to another club (or removing them from a plantel) deletes the
 * old player_team links. What survives of the past are the match events and the
 * sanctions, which keep the plantel the player had at that moment -- so the
 * "clubs and planteles" timeline below is DEDUCED from them (plus the current
 * roster), and the page says so. Nor is there any record of who played each
 * match, so no matches-played figure exists.
 */
class PlayerProfileService
{
    /**
     * One entry per player of the organizer, ready to be filtered in the
     * browser. The haystack is everything searchable about the player
     * (name, document, current and past clubs), lowercased and without
     * accents -- the page normalizes what is typed the same way.
     *
     * @return list<array{id: int, name: string, document: string|null, inactive: bool, url: string, teams: list<array{club: string, category: string}>, past_clubs: list<string>, haystack: string}>
     */
    public function searchCatalog(int $ownerId): array
    {
        $players = Player::allForOrganizer($ownerId);
        $pastClubs = $this->clubNamesFromHistory($players->pluck('id')->all());

        return $players
            ->map(function (Player $player) use ($pastClubs): array {
                $teams = $player->allTeams();
                $currentClubs = $teams->map(fn (Team $team): string => $this->clubLabel($team))->unique()->values();
                $past = collect($pastClubs[$player->id] ?? [])->reject(fn (string $name): bool => $currentClubs->contains($name))->values();

                return [
                    'id' => $player->id,
                    'name' => $player->full_name,
                    'document' => $player->document_number,
                    'inactive' => ! $player->is_active,
                    'url' => route('players.show', $player),
                    'teams' => $teams->map(fn (Team $team): array => ['club' => $this->clubLabel($team), 'category' => $team->category->name])->all(),
                    'past_clubs' => $past->all(),
                    'haystack' => Str::ascii(mb_strtolower(collect([$player->full_name, $player->document_number])->merge($currentClubs)->merge($past)->filter()->implode(' '))),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     player: Player,
     *     currentTeams: Collection<int, Team>,
     *     totals: array{goals: int, assists: int, yellow_cards: int, red_cards: int, sanctions: int, tournaments: int},
     *     tournaments: list<array{tournament: Tournament, team: Team, current: bool, goals: int, assists: int, yellow_cards: int, red_cards: int, last_at: CarbonInterface|null}>,
     *     sanctions: Collection<int, Sanction>,
     *     timeline: list<array{team: Team, current: bool, jersey: int|null, tournaments: list<string>, first_at: CarbonInterface|null, last_at: CarbonInterface|null}>
     * }
     */
    public function profile(Player $player): array
    {
        $player->load(['team.club', 'team.category', 'team.group', 'team.tournament', 'team.tournaments', 'teams.club', 'teams.category', 'teams.group', 'teams.tournaments']);

        $currentTeams = $player->allTeams();

        $events = MatchEvent::query()
            ->where('player_id', $player->id)
            ->with(['match.tournament', 'match.category', 'team.club', 'team.category', 'team.group'])
            ->get();

        $sanctions = Sanction::query()
            ->where('player_id', $player->id)
            ->with(['match.tournament', 'match.category', 'match.homeTeam', 'match.awayTeam', 'team.club', 'team.category'])
            ->orderByDesc('id')
            ->get();

        $tournaments = $this->tournamentRows($currentTeams, $events);

        return [
            'player' => $player,
            'currentTeams' => $currentTeams,
            'totals' => [
                'goals' => $events->where('type', MatchEventType::Goal)->count(),
                'assists' => $events->where('type', MatchEventType::Assist)->count(),
                'yellow_cards' => $events->where('type', MatchEventType::YellowCard)->count(),
                'red_cards' => $events->where('type', MatchEventType::RedCard)->count(),
                'sanctions' => $sanctions->count(),
                'tournaments' => collect($tournaments)->pluck('tournament.id')->unique()->count(),
            ],
            'tournaments' => $tournaments,
            'sanctions' => $sanctions,
            'timeline' => $this->timeline($player, $currentTeams, $events, $sanctions),
        ];
    }

    /**
     * One row per tournament + plantel: what the player did there (from the
     * events) and, for the current planteles, the tournaments they're
     * enrolled in even before any event exists.
     *
     * @param  Collection<int, Team>  $currentTeams
     * @param  Collection<int, MatchEvent>  $events
     * @return list<array{tournament: Tournament, team: Team, current: bool, goals: int, assists: int, yellow_cards: int, red_cards: int, last_at: CarbonInterface|null}>
     */
    private function tournamentRows(Collection $currentTeams, Collection $events): array
    {
        $rows = [];
        $blank = ['goals' => 0, 'assists' => 0, 'yellow_cards' => 0, 'red_cards' => 0, 'last_at' => null];

        foreach ($currentTeams as $team) {
            foreach ($team->tournaments->concat([$team->tournament])->filter()->unique('id') as $tournament) {
                $rows[$tournament->id.'|'.$team->id] = ['tournament' => $tournament, 'team' => $team, 'current' => true, ...$blank];
            }
        }

        foreach ($events as $event) {
            $tournament = $event->match->tournament;
            $key = $tournament->id.'|'.$event->team_id;

            $rows[$key] ??= ['tournament' => $tournament, 'team' => $event->team, 'current' => $currentTeams->contains('id', $event->team_id), ...$blank];

            $counter = match ($event->type) {
                MatchEventType::Goal => 'goals',
                MatchEventType::Assist => 'assists',
                MatchEventType::YellowCard => 'yellow_cards',
                MatchEventType::RedCard => 'red_cards',
            };
            $rows[$key][$counter]++;

            $at = $this->eventDate($event);
            $rows[$key]['last_at'] = $rows[$key]['last_at'] === null || $at->gt($rows[$key]['last_at']) ? $at : $rows[$key]['last_at'];
        }

        return collect($rows)
            ->sortByDesc(fn (array $row): int => $row['tournament']->id)
            ->values()
            ->all();
    }

    /**
     * Every plantel the player is known to have had: the current ones, and
     * any other that shows up in their events or sanctions. Nothing else is
     * knowable -- see the class note.
     *
     * @param  Collection<int, Team>  $currentTeams
     * @param  Collection<int, MatchEvent>  $events
     * @param  Collection<int, Sanction>  $sanctions
     * @return list<array{team: Team, current: bool, jersey: int|null, tournaments: list<string>, first_at: CarbonInterface|null, last_at: CarbonInterface|null}>
     */
    private function timeline(Player $player, Collection $currentTeams, Collection $events, Collection $sanctions): array
    {
        $entries = [];

        foreach ($currentTeams as $team) {
            $entries[$team->id] = [
                'team' => $team,
                'current' => true,
                'jersey' => $team->id === $player->team_id ? $player->jersey_number : $team->pivot?->jersey_number,
                'tournaments' => [],
                'first_at' => null,
                'last_at' => null,
            ];
        }

        $touch = function (Team $team, string $tournamentName, CarbonInterface $at) use (&$entries): void {
            $entries[$team->id] ??= ['team' => $team, 'current' => false, 'jersey' => null, 'tournaments' => [], 'first_at' => null, 'last_at' => null];

            $entries[$team->id]['tournaments'] = collect($entries[$team->id]['tournaments'])->push($tournamentName)->unique()->values()->all();
            $entries[$team->id]['first_at'] = $entries[$team->id]['first_at'] === null || $at->lt($entries[$team->id]['first_at']) ? $at : $entries[$team->id]['first_at'];
            $entries[$team->id]['last_at'] = $entries[$team->id]['last_at'] === null || $at->gt($entries[$team->id]['last_at']) ? $at : $entries[$team->id]['last_at'];
        };

        foreach ($events as $event) {
            $touch($event->team, $event->match->tournament->name, $this->eventDate($event));
        }

        foreach ($sanctions as $sanction) {
            $touch($sanction->team, $sanction->match->tournament->name, $sanction->match->scheduled_at ?? $sanction->created_at);
        }

        return collect($entries)
            ->sortBy([
                fn (array $a, array $b): int => (int) $b['current'] <=> (int) $a['current'],
                fn (array $a, array $b): int => ($b['last_at']?->timestamp ?? 0) <=> ($a['last_at']?->timestamp ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * Club names each player appears with in the events and sanctions --
     * the only trace of past clubs there is.
     *
     * @param  list<int>  $playerIds
     * @return array<int, list<string>>
     */
    private function clubNamesFromHistory(array $playerIds): array
    {
        if ($playerIds === []) {
            return [];
        }

        $fromEvents = MatchEvent::query()
            ->whereIn('match_events.player_id', $playerIds)
            ->join('teams', 'teams.id', '=', 'match_events.team_id')
            ->join('clubs', 'clubs.id', '=', 'teams.club_id')
            ->select('match_events.player_id', 'clubs.name')
            ->distinct()
            ->get();

        $fromSanctions = Sanction::query()
            ->whereIn('sanctions.player_id', $playerIds)
            ->join('teams', 'teams.id', '=', 'sanctions.team_id')
            ->join('clubs', 'clubs.id', '=', 'teams.club_id')
            ->select('sanctions.player_id', 'clubs.name')
            ->distinct()
            ->get();

        return $fromEvents->concat($fromSanctions)
            ->groupBy('player_id')
            ->map(fn (Collection $rows): array => $rows->pluck('name')->unique()->values()->all())
            ->all();
    }

    private function eventDate(MatchEvent $event): CarbonInterface
    {
        return $event->match->scheduled_at ?? $event->created_at;
    }

    private function clubLabel(Team $team): string
    {
        return $team->club?->name ?? $team->name;
    }
}
