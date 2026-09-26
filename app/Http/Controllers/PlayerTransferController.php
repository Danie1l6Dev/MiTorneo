<?php

namespace App\Http\Controllers;

use App\Http\Requests\PlayerTransferRequest;
use App\Models\Club;
use App\Models\Player;
use App\Models\Team;
use App\Services\PlayerRosterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * "Transferir jugador": moves a player to another club in one step -- what used
 * to take deactivating them at the old club and then registering them at the new
 * one, with no date and no reason. Records the transfer in the player's history
 * (PlayerRosterService::moveToClub()).
 */
class PlayerTransferController extends Controller
{
    public function create(Player $player): View
    {
        $this->authorize('update', $player);

        $player->load(['team.club', 'team.category', 'teams.club', 'teams.category']);

        $currentClubIds = $player->clubIds();

        // Every other club of the organizer with its own planteles (not the
        // per-tournament legacy ones), each flagged with whether the player's age
        // fits its category -- the page shows a club's planteles instantly from this.
        $clubs = Auth::user()->clubs()
            ->whereNotIn('id', $currentClubIds)
            ->with(['teams' => fn ($query) => $query->whereNull('tournament_id')->with(['category', 'group'])])
            ->orderBy('name')
            ->get()
            ->map(fn (Club $club): array => [
                'id' => $club->id,
                'name' => $club->name,
                'teams' => Team::sortedByCategoryAge($club->teams)->map(fn (Team $team): array => [
                    'id' => $team->id,
                    'category' => $team->category->name,
                    'group' => $team->group?->name,
                    'eligible' => $player->ageEligibleForCategory($team->category),
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return view('pages.players.transfer', [
            'player' => $player,
            'currentTeams' => $player->allTeams(),
            'clubs' => $clubs,
            'today' => Carbon::today()->toDateString(),
        ]);
    }

    public function store(PlayerTransferRequest $request, Player $player, PlayerRosterService $roster): RedirectResponse
    {
        $this->authorize('update', $player);

        $club = Club::query()->findOrFail($request->integer('club_id'));
        $teams = Team::sortedByCategoryAge(Team::query()->with('category')->whereIn('id', $request->input('team_ids'))->get())->values();

        // The youngest category chosen becomes the player's own plantel (team_id);
        // any others are extra planteles -- the same rule the club-level form uses.
        $player->jersey_number = $request->jerseyNumber($player);

        $roster->moveToClub(
            $player,
            $teams->first(),
            $teams->slice(1),
            Carbon::parse($request->input('date')),
            $request->filled('notes') ? trim((string) $request->input('notes')) : null,
        );

        return to_route('players.show', $player)->with('status', __(':name fue transferido a :club.', ['name' => $player->full_name, 'club' => $club->name]));
    }
}
