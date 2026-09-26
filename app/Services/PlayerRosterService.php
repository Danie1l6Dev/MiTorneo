<?php

namespace App\Services;

use App\Enums\RosterEndReason;
use App\Enums\RosterStartReason;
use App\Models\Player;
use App\Models\PlayerTeamHistory;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The ONE place a player's plantel links change. Every add, removal, promotion
 * and club move goes through here so the roster (players.team_id + the
 * player_team pivot) and the history (player_team_history) are written in the
 * same transaction and can't drift apart -- nothing else should attach/detach
 * a player's teams or reassign team_id (tests/Feature/Tournaments/
 * PlayerRosterHistoryGuardTest fails if something does).
 *
 * The primary plantel (players.team_id) and the extra ones (the pivot) are both
 * "a plantel the player is on", so both get history lines.
 */
class PlayerRosterService
{
    /**
     * A player just created with players.team_id already set: opens their first
     * history line, links any extra planteles chosen in the same step (with
     * their own line each).
     *
     * @param  iterable<int>  $extraTeamIds
     */
    public function enrollNew(Player $player, iterable $extraTeamIds = []): void
    {
        DB::transaction(function () use ($player, $extraTeamIds): void {
            $this->open($player, $player->team()->firstOrFail(), RosterStartReason::Registered, $player->jersey_number);

            foreach (Team::query()->whereIn('id', collect($extraTeamIds)->all())->get() as $team) {
                $this->addTeam($player, $team, RosterStartReason::Registered);
            }
        });
    }

    /**
     * Links an extra plantel (a pivot row) and opens its history line.
     */
    public function addTeam(Player $player, Team $team, RosterStartReason $reason = RosterStartReason::Added, ?int $jerseyNumber = null): void
    {
        DB::transaction(function () use ($player, $team, $reason, $jerseyNumber): void {
            $player->teams()->attach($team->id, ['jersey_number' => $jerseyNumber]);

            $this->open($player, $team, $reason, $jerseyNumber);
        });
    }

    /**
     * Links several extra planteles at once (already-linked ones are the
     * caller's to filter out).
     *
     * @param  iterable<Team>  $teams
     */
    public function addTeams(Player $player, iterable $teams, RosterStartReason $reason = RosterStartReason::Added): void
    {
        DB::transaction(function () use ($player, $teams, $reason): void {
            foreach ($teams as $team) {
                $this->addTeam($player, $team, $reason);
            }
        });
    }

    /**
     * Takes an extra plantel (a pivot row) off the player and closes its line.
     */
    public function removeTeam(Player $player, Team $team, RosterEndReason $reason = RosterEndReason::Removed): void
    {
        DB::transaction(function () use ($player, $team, $reason): void {
            $player->teams()->detach($team->id);

            $this->close($player, $team, $reason);
        });
    }

    /**
     * Moves the player to another club: closes every line they have open (they
     * "left"), drops all their old links, makes $primary their team_id (plus
     * $extraTeams as extra planteles) and opens a line for each -- all as a
     * transfer, on $on (today by default) with an optional note. Attributes the
     * caller already set on $player (a new jersey number, say) are saved along.
     * Leaves the player active, since they're playing at the new club.
     *
     * @param  iterable<Team>  $extraTeams
     */
    public function moveToClub(Player $player, Team $primary, iterable $extraTeams = [], ?CarbonInterface $on = null, ?string $notes = null): void
    {
        DB::transaction(function () use ($player, $primary, $extraTeams, $on, $notes): void {
            $this->closeAllOpen($player, RosterEndReason::Transferred, $on);

            $player->teams()->detach();
            $player->team_id = $primary->id;
            $player->is_active = true;
            $player->save();

            $this->open($player, $primary, RosterStartReason::Transferred, $player->jersey_number, $on, $notes);

            foreach ($extraTeams as $team) {
                $player->teams()->attach($team->id);
                $this->open($player, $team, RosterStartReason::Transferred, null, $on, $notes);
            }
        });
    }

    /**
     * Moves the player from $from to $to (another plantel of the same club),
     * replacing the link: whichever way they were on $from -- team_id or a
     * pivot row -- is what's replaced, and a pivot jersey number carries over.
     * $from's line closes as promoted and $to's opens the same way.
     */
    public function promote(Player $player, Team $from, Team $to): void
    {
        DB::transaction(function () use ($player, $from, $to): void {
            $jerseyNumber = $player->jersey_number;

            if ($player->team_id === $from->id) {
                $player->team_id = $to->id;
                $player->save();
            } else {
                $jerseyNumber = DB::table('player_team')
                    ->where('player_id', $player->id)
                    ->where('team_id', $from->id)
                    ->value('jersey_number');

                $player->teams()->detach($from->id);
                $player->teams()->attach($to->id, ['jersey_number' => $jerseyNumber]);
            }

            $this->close($player, $from, RosterEndReason::Promoted);
            $this->open($player, $to, RosterStartReason::Promoted, $jerseyNumber);
        });
    }

    /**
     * $team becomes the player's team_id and its pivot row goes away -- they were
     * already on it, so their history doesn't change. What a club-wide removal
     * does when the primary plantel is one of the removed ones and another
     * plantel is left.
     */
    public function makePrimary(Player $player, Team $team): void
    {
        DB::transaction(function () use ($player, $team): void {
            $player->team_id = $team->id;
            $player->save();
            $player->teams()->detach($team->id);
        });
    }

    /**
     * Takes several planteles off the player at once -- pivot rows dropped
     * (the primary one has none, that part is the caller's) and every open line
     * on them closed.
     *
     * @param  iterable<Team>  $teams
     */
    public function removeTeams(Player $player, iterable $teams, RosterEndReason $reason = RosterEndReason::Removed): void
    {
        DB::transaction(function () use ($player, $teams, $reason): void {
            foreach ($teams as $team) {
                $player->teams()->detach($team->id);

                $this->close($player, $team, $reason);
            }
        });
    }

    /**
     * A plantel is about to be deleted: closes every player's open line on it.
     */
    public function closeTeam(Team $team): void
    {
        PlayerTeamHistory::query()
            ->where('team_id', $team->id)
            ->open()
            ->update(['ended_on' => now()->toDateString(), 'end_reason' => RosterEndReason::TeamDeleted->value]);
    }

    /**
     * Opens a history line for $player on $team (a no-op returning the existing
     * one when they already have one open there), copying the names so it keeps
     * reading right if the plantel is renamed or deleted later.
     */
    public function open(Player $player, Team $team, RosterStartReason $reason, ?int $jerseyNumber = null, ?CarbonInterface $on = null, ?string $notes = null): PlayerTeamHistory
    {
        $existing = $player->teamHistory()->open()->where('team_id', $team->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $team->loadMissing(['club', 'category', 'group']);

        return $player->teamHistory()->create([
            'team_id' => $team->id,
            'club_id' => $team->club_id,
            'club_name' => $team->club?->name,
            'team_name' => $team->name,
            'category_name' => $team->category?->name,
            'group_name' => $team->group?->name,
            'jersey_number' => $jerseyNumber,
            'started_on' => ($on ?? now())->toDateString(),
            'start_reason' => $reason,
            'notes' => $notes,
        ]);
    }

    private function close(Player $player, Team $team, RosterEndReason $reason, ?CarbonInterface $on = null): void
    {
        $player->teamHistory()
            ->open()
            ->where('team_id', $team->id)
            ->update(['ended_on' => ($on ?? now())->toDateString(), 'end_reason' => $reason->value]);
    }

    private function closeAllOpen(Player $player, RosterEndReason $reason, ?CarbonInterface $on = null): void
    {
        $player->teamHistory()
            ->open()
            ->update(['ended_on' => ($on ?? now())->toDateString(), 'end_reason' => $reason->value]);
    }
}
