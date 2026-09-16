<?php

namespace App\Http\Controllers;

use App\Enums\MatchStatus;
use App\Http\Requests\TeamExpulsionRequest;
use App\Models\Category;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Services\TeamExpulsionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Expelling a plantel from a tournament's category -- see
 * TeamExpulsionService for what this actually does to its remaining
 * matches. Authorization mirrors TournamentCategoryController's own
 * roster-management actions: it's the tournament (not the team) that's
 * updated, since it's the organizer's call over THIS tournament's edition
 * of the category.
 */
class TeamExpulsionController extends Controller
{
    /**
     * Confirms the category (still tournament-scoped the same way
     * TournamentCategoryController::show() does) and that $team is actually
     * one of its enrolled planteles -- a team from another category, or one
     * never inscribed in this tournament, has nothing here to expel.
     */
    private function assertTeamBelongs(Tournament $tournament, Category $category, Team $team): void
    {
        $belongsToTournament = $category->tournament_id
            ? $category->tournament_id === $tournament->id
            : $tournament->globalCategories()->whereKey($category->id)->exists();

        abort_unless($belongsToTournament, 404);
        abort_unless($team->category_id === $category->id, 404);
        abort_unless($category->teamsForTournament($tournament)->contains('id', $team->id), 404);
    }

    public function create(Tournament $tournament, Category $category, Team $team): View
    {
        $this->authorize('update', $tournament);

        $this->assertTeamBelongs($tournament, $category, $team);

        $pendingMatches = TournamentMatch::query()
            ->where('tournament_id', $tournament->id)
            ->where('category_id', $category->id)
            ->where(fn ($query) => $query->where('home_team_id', $team->id)->orWhere('away_team_id', $team->id))
            ->whereNotNull('home_team_id')
            ->whereNotNull('away_team_id')
            ->whereNotIn('status', [MatchStatus::Finished, MatchStatus::Cancelled])
            ->with(['homeTeam', 'awayTeam'])
            ->orderBy('scheduled_at')
            ->get();

        return view('pages.tournaments.categories.teams.expel', compact('tournament', 'category', 'team', 'pendingMatches'));
    }

    public function store(TeamExpulsionRequest $request, Tournament $tournament, Category $category, Team $team, TeamExpulsionService $service): RedirectResponse
    {
        $this->authorize('update', $tournament);

        $this->assertTeamBelongs($tournament, $category, $team);

        if ($team->isExpelledFrom($tournament)) {
            return to_route('tournaments.categories.show', [$tournament, $category])
                ->with('error', __('Este plantel ya fue expulsado de este torneo.'));
        }

        $service->expel($team, $tournament, $request->validated('reason'));

        return to_route('tournaments.categories.show', [$tournament, $category])
            ->with('status', __(':team fue expulsado de :category. Sus partidos pendientes quedaron como 0-3 (perdido por W).', [
                'team' => $team->name,
                'category' => $category->name,
            ]));
    }

    public function destroy(Tournament $tournament, Category $category, Team $team, TeamExpulsionService $service): RedirectResponse
    {
        $this->authorize('update', $tournament);

        $this->assertTeamBelongs($tournament, $category, $team);

        if (! $team->isExpelledFrom($tournament)) {
            return back()->with('error', __('Este plantel no está expulsado de este torneo.'));
        }

        $service->revert($team, $tournament);

        return to_route('tournaments.categories.show', [$tournament, $category])
            ->with('status', __('Se revirtió la expulsión de :team.', ['team' => $team->name]));
    }
}
