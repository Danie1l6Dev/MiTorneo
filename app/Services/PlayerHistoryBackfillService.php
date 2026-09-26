<?php

namespace App\Services;

use App\Enums\RosterEndReason;
use App\Enums\RosterStartReason;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\PlayerTeamHistory;
use App\Models\Sanction;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Builds the history players had before it started being recorded
 * (PlayerRosterService), and checks the recorded history against the real
 * roster. Only ever INSERTS rows (flagged is_estimated) -- it never touches the
 * roster or an existing history line -- and running it again adds nothing new.
 *
 *  - Every plantel the player is on right now, with no open line, gets one:
 *    started when the link was registered (the pivot row's created_at, or the
 *    player's own for the primary plantel).
 *  - Every OTHER plantel that shows up in the player's goals, cards or sanctions
 *    gets a closed line spanning their first to last such record, ending
 *    "motivo desconocido": it's the only trace of a past plantel there is.
 */
class PlayerHistoryBackfillService
{
    /**
     * @return array{players: int, current: int, past: int, rows: list<array{player: string, team: string, kind: string, from: string, to: string|null}>}
     */
    public function run(bool $dryRun = false, ?int $ownerId = null): array
    {
        $report = ['players' => 0, 'current' => 0, 'past' => 0, 'rows' => []];

        Player::query()
            ->when($ownerId !== null, fn ($query) => $query->ownedBy($ownerId))
            ->with(['team.club', 'team.category', 'team.group', 'teams.club', 'teams.category', 'teams.group', 'teamHistory'])
            ->chunkById(200, function (Collection $players) use ($dryRun, &$report): void {
                $activity = $this->activityByPlayerAndTeam($players->pluck('id')->all());

                foreach ($players as $player) {
                    $report['players']++;

                    foreach ($this->missingLines($player, $activity[$player->id] ?? []) as $line) {
                        $report[$line['kind']]++;
                        $report['rows'][] = [
                            'player' => $player->full_name,
                            'team' => ($line['team']->club?->name ?? $line['team']->name).' · '.($line['team']->category?->name ?? ''),
                            'kind' => $line['kind'] === 'current' ? 'actual' : 'pasado',
                            'from' => $line['attributes']['started_on'],
                            'to' => $line['attributes']['ended_on'] ?? null,
                        ];

                        if (! $dryRun) {
                            $player->teamHistory()->create($line['attributes']);
                        }
                    }
                }
            });

        return $report;
    }

    /**
     * Where the recorded history and the real roster disagree.
     *
     * @return list<array{player: string, problem: string}>
     */
    public function drift(?int $ownerId = null): array
    {
        $problems = [];

        Player::query()
            ->when($ownerId !== null, fn ($query) => $query->ownedBy($ownerId))
            ->with(['team', 'teams', 'teamHistory'])
            ->chunkById(200, function (Collection $players) use (&$problems): void {
                foreach ($players as $player) {
                    $currentIds = $player->allTeams()->pluck('id');
                    $openIds = $player->teamHistory->filter(fn (PlayerTeamHistory $line): bool => $line->isOpen())->pluck('team_id')->filter();

                    foreach ($currentIds->diff($openIds) as $teamId) {
                        $problems[] = ['player' => $player->full_name, 'problem' => "está en el plantel #{$teamId} pero su historial no tiene una línea abierta ahí"];
                    }

                    foreach ($openIds->diff($currentIds) as $teamId) {
                        $problems[] = ['player' => $player->full_name, 'problem' => "su historial tiene una línea abierta en el plantel #{$teamId} pero ya no está en él"];
                    }
                }
            });

        return $problems;
    }

    /**
     * @param  array<int, array{first: CarbonInterface, last: CarbonInterface}>  $activity  Keyed by team id.
     * @return list<array{kind: string, team: Team, attributes: array<string, mixed>}>
     */
    private function missingLines(Player $player, array $activity): array
    {
        $lines = [];
        $currentTeams = $player->allTeams();
        $hasAnyLine = $player->teamHistory->pluck('team_id')->filter();
        $hasOpenLine = $player->teamHistory->filter(fn (PlayerTeamHistory $line): bool => $line->isOpen())->pluck('team_id')->filter();

        foreach ($currentTeams as $team) {
            if ($hasOpenLine->contains($team->id)) {
                continue;
            }

            $isPrimary = $team->id === $player->team_id;
            $since = $isPrimary ? $player->created_at : ($team->pivot?->created_at ?? $player->created_at);

            $lines[] = [
                'kind' => 'current',
                'team' => $team,
                'attributes' => $this->snapshot($team) + [
                    'jersey_number' => $isPrimary ? $player->jersey_number : $team->pivot?->jersey_number,
                    'started_on' => ($since ?? now())->toDateString(),
                    'ended_on' => null,
                    'start_reason' => RosterStartReason::Estimated,
                    'end_reason' => null,
                    'is_estimated' => true,
                ],
            ];
        }

        $pastTeamIds = collect(array_keys($activity))->diff($currentTeams->pluck('id'))->diff($hasAnyLine);

        foreach (Team::query()->with(['club', 'category', 'group'])->whereIn('id', $pastTeamIds)->get() as $team) {
            $lines[] = [
                'kind' => 'past',
                'team' => $team,
                'attributes' => $this->snapshot($team) + [
                    'jersey_number' => null,
                    'started_on' => $activity[$team->id]['first']->toDateString(),
                    'ended_on' => $activity[$team->id]['last']->toDateString(),
                    'start_reason' => RosterStartReason::Estimated,
                    'end_reason' => RosterEndReason::Unknown,
                    'is_estimated' => true,
                ],
            ];
        }

        return $lines;
    }

    /**
     * First and last date of each player's goals, cards and sanctions per
     * plantel -- the match's date when it has one, else when the record was made.
     *
     * @param  list<int>  $playerIds
     * @return array<int, array<int, array{first: CarbonInterface, last: CarbonInterface}>>
     */
    private function activityByPlayerAndTeam(array $playerIds): array
    {
        $activity = [];

        $touch = function (int $playerId, int $teamId, CarbonInterface $at) use (&$activity): void {
            $entry = $activity[$playerId][$teamId] ?? ['first' => $at, 'last' => $at];

            $activity[$playerId][$teamId] = [
                'first' => $at->lt($entry['first']) ? $at : $entry['first'],
                'last' => $at->gt($entry['last']) ? $at : $entry['last'],
            ];
        };

        foreach (MatchEvent::query()->whereIn('player_id', $playerIds)->with('match')->get() as $event) {
            $touch($event->player_id, $event->team_id, $event->match->scheduled_at ?? $event->created_at);
        }

        foreach (Sanction::query()->whereIn('player_id', $playerIds)->with('match')->get() as $sanction) {
            $touch($sanction->player_id, $sanction->team_id, $sanction->match->scheduled_at ?? $sanction->created_at);
        }

        return $activity;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Team $team): array
    {
        return [
            'team_id' => $team->id,
            'club_id' => $team->club_id,
            'club_name' => $team->club?->name,
            'team_name' => $team->name,
            'category_name' => $team->category?->name,
            'group_name' => $team->group?->name,
        ];
    }
}
