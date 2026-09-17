<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\Sanction;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\View\View;

/**
 * The public portal's page for one team's plantel -- read-only, reachable
 * without logging in (see routes/public.php). Shows only what a visitor
 * following the tournament needs (roster, coach, active sanctions,
 * expulsion status): document numbers and other personal data the admin
 * roster view shows are deliberately left out, since this page has no login
 * wall.
 */
class PublicTeamController extends Controller
{
    public function show(Tournament $tournament, Team $team): View
    {
        // $team is bound straight from its own id, so a mismatched pair in
        // the URL must 404 instead of silently showing a team that never
        // played in this tournament. Same legacy-vs-promoted check
        // PublicCategoryController already uses.
        $belongsToTournament = $team->tournament_id
            ? $team->tournament_id === $tournament->id
            : $tournament->globalTeams()->whereKey($team->id)->exists();

        if (! $belongsToTournament) {
            abort(404);
        }

        $team->load(['category', 'group', 'coach']);

        $roster = $team->players()->where('is_active', true)->get();

        if (! $team->tournament_id) {
            $roster = $roster->merge($team->globalPlayers()->where('is_active', true)->get())->unique('id');
        }

        $roster = $roster->sortBy(fn (Player $player): array => [$player->jersey_number ?? PHP_INT_MAX, $player->full_name])->values();

        // Every sanction still owed by a player/coach of this plantel --
        // pending or actively being served, same "suspended right now"
        // meaning Sanction::stillOwesFechas() already gives the admin side.
        $activeSanctionsBySubject = Sanction::query()
            ->where('team_id', $team->id)
            ->with(['player', 'coach'])
            ->get()
            ->filter->stillOwesFechas()
            ->groupBy(fn (Sanction $sanction): string => $sanction->player_id !== null
                ? 'player-'.$sanction->player_id
                : 'coach-'.$sanction->coach_id);

        $isExpelled = $team->isExpelledFrom($tournament);
        $expulsionReason = $team->expulsionReasonFor($tournament);
        $expulsionResolutionPdfUrl = $team->expulsionResolutionPdfUrlFor($tournament);
        $expelledAt = $team->expelledAtFor($tournament);

        return view('pages.public.teams.show', compact(
            'tournament', 'team', 'roster', 'activeSanctionsBySubject', 'isExpelled', 'expulsionReason', 'expulsionResolutionPdfUrl', 'expelledAt'
        ));
    }
}
