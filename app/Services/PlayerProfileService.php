<?php

namespace App\Services;

use App\Enums\MatchEventType;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\PlayerTeamHistory;
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
 * The clubs/planteles timeline comes from player_team_history (written by
 * PlayerRosterService since it exists, with real dates and reasons). Whatever
 * has no line there -- players whose past was never rebuilt, or a plantel that
 * only shows up in their goals, cards and sanctions -- is DEDUCED from those
 * records plus the current roster, and flagged "estimado". Nor is there any
 * record of who played each match, so no matches-played figure exists.
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
     *     timeline: list<array{club: string, category: string|null, group: string|null, current: bool, jersey: int|null, tournaments: list<string>, from: CarbonInterface|null, to: CarbonInterface|null, start_reason: string|null, end_reason: string|null, estimated: bool, notes: string|null}>
     * }
     */
    public function profile(Player $player): array
    {
        $player->load(['team.club', 'team.category', 'team.group', 'team.tournament', 'team.tournaments', 'teams.club', 'teams.category', 'teams.group', 'teams.tournaments', 'teamHistory']);

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
     * The player's stays on planteles, current first: the recorded history, plus
     * -- flagged estimated -- any plantel that only their events and sanctions
     * reveal (or everything, deduced, for a player with no history at all).
     *
     * @param  Collection<int, Team>  $currentTeams
     * @param  Collection<int, MatchEvent>  $events
     * @param  Collection<int, Sanction>  $sanctions
     * @return list<array{club: string, category: string|null, group: string|null, current: bool, jersey: int|null, tournaments: list<string>, from: CarbonInterface|null, to: CarbonInterface|null, start_reason: string|null, end_reason: string|null, estimated: bool, notes: string|null}>
     */
    private function timeline(Player $player, Collection $currentTeams, Collection $events, Collection $sanctions): array
    {
        $deduced = $this->deducedEntries($player, $currentTeams, $events, $sanctions);
        $entries = [];

        foreach ($player->teamHistory as $line) {
            $entries[] = [
                'club' => $line->clubLabel(),
                'category' => $line->category_name,
                'group' => $line->group_name,
                'current' => $line->isOpen(),
                'jersey' => $line->jersey_number,
                'tournaments' => $deduced[$line->team_id]['tournaments'] ?? [],
                'from' => $line->started_on,
                'to' => $line->ended_on,
                'start_reason' => $line->start_reason->label(),
                'end_reason' => $line->end_reason?->label(),
                'estimated' => $line->is_estimated,
                'notes' => $line->notes,
            ];
        }

        $covered = $player->teamHistory->pluck('team_id')->filter();

        foreach ($deduced as $teamId => $entry) {
            if (! $covered->contains($teamId)) {
                $entries[] = $entry;
            }
        }

        return collect($entries)
            ->sortBy([
                fn (array $a, array $b): int => (int) $b['current'] <=> (int) $a['current'],
                fn (array $a, array $b): int => ($b['to']?->timestamp ?? PHP_INT_MAX) <=> ($a['to']?->timestamp ?? PHP_INT_MAX),
                fn (array $a, array $b): int => ($b['from']?->timestamp ?? 0) <=> ($a['from']?->timestamp ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * What can be deduced without the history: the current planteles, and any
     * other that shows up in the player's events or sanctions, keyed by plantel
     * id, with the tournaments and the first/last date of those records.
     *
     * @param  Collection<int, Team>  $currentTeams
     * @param  Collection<int, MatchEvent>  $events
     * @param  Collection<int, Sanction>  $sanctions
     * @return array<int, array{club: string, category: string|null, group: string|null, current: bool, jersey: int|null, tournaments: list<string>, from: CarbonInterface|null, to: CarbonInterface|null, start_reason: string|null, end_reason: string|null, estimated: bool, notes: string|null}>
     */
    private function deducedEntries(Player $player, Collection $currentTeams, Collection $events, Collection $sanctions): array
    {
        $entries = [];

        $blank = fn (Team $team, bool $current, ?int $jersey): array => [
            'club' => $this->clubLabel($team),
            'category' => $team->category?->name,
            'group' => $team->group?->name,
            'current' => $current,
            'jersey' => $jersey,
            'tournaments' => [],
            'from' => null,
            'to' => null,
            'start_reason' => null,
            'end_reason' => null,
            'estimated' => true,
            'notes' => null,
        ];

        foreach ($currentTeams as $team) {
            $entries[$team->id] = $blank($team, true, $team->id === $player->team_id ? $player->jersey_number : $team->pivot?->jersey_number);
        }

        $touch = function (Team $team, string $tournamentName, CarbonInterface $at) use (&$entries, $blank): void {
            $entries[$team->id] ??= $blank($team, false, null);

            $entries[$team->id]['tournaments'] = collect($entries[$team->id]['tournaments'])->push($tournamentName)->unique()->values()->all();
            $entries[$team->id]['from'] = $entries[$team->id]['from'] === null || $at->lt($entries[$team->id]['from']) ? $at : $entries[$team->id]['from'];
            $entries[$team->id]['to'] = $entries[$team->id]['to'] === null || $at->gt($entries[$team->id]['to']) ? $at : $entries[$team->id]['to'];
        };

        foreach ($events as $event) {
            $touch($event->team, $event->match->tournament->name, $this->eventDate($event));
        }

        foreach ($sanctions as $sanction) {
            $touch($sanction->team, $sanction->match->tournament->name, $sanction->match->scheduled_at ?? $sanction->created_at);
        }

        return $entries;
    }

    /**
     * Club names each player has been at: from their recorded history and, for
     * whatever it doesn't cover, from their events and sanctions.
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

        $fromHistory = PlayerTeamHistory::query()
            ->whereIn('player_id', $playerIds)
            ->whereNotNull('club_name')
            ->select('player_id', 'club_name as name')
            ->distinct()
            ->get();

        return $fromEvents->concat($fromSanctions)->concat($fromHistory)
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
