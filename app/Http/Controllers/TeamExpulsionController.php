<?php

namespace App\Http\Controllers;

use App\Enums\MatchStatus;
use App\Http\Requests\ResolutionPdfRequest;
use App\Http\Requests\TeamExpulsionRequest;
use App\Models\Category;
use App\Models\Setting;
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

        $pdfUploadsEnabled = Setting::sanctionPdfUploadsEnabled();

        return view('pages.tournaments.categories.teams.expel', compact('tournament', 'category', 'team', 'pendingMatches', 'pdfUploadsEnabled'));
    }

    public function store(TeamExpulsionRequest $request, Tournament $tournament, Category $category, Team $team, TeamExpulsionService $service): RedirectResponse
    {
        $this->authorize('update', $tournament);

        $this->assertTeamBelongs($tournament, $category, $team);

        if ($team->isExpelledFrom($tournament)) {
            return to_route('tournaments.categories.show', [$tournament, $category])
                ->with('error', __('Este plantel ya fue expulsado de este torneo.'));
        }

        // See SanctionController::resolve() -- gate on the flag here too,
        // not just in TeamExpulsionRequest::rules(), so a stray upload
        // can't be stored while the feature is supposed to be off.
        $resolutionPdfPath = Setting::sanctionPdfUploadsEnabled() && $request->hasFile('resolution_pdf')
            ? $request->file('resolution_pdf')->store('resoluciones', 'public')
            : null;

        $service->expel($team, $tournament, $resolutionPdfPath === null ? $request->validated('reason') : null, $resolutionPdfPath);

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

    /**
     * Read-only detail page for one team's expulsion -- the organizer-facing
     * counterpart to SanctionController::show() for a player/DT sanction,
     * reached from the same sanctions index. Includes every match this
     * expulsion itself forced to a 0-3 walkover, for the same transparency
     * reason the sanction page lists its own serving-window matches.
     */
    public function show(Tournament $tournament, Category $category, Team $team): View
    {
        $this->authorize('update', $tournament);

        $this->assertTeamBelongs($tournament, $category, $team);

        abort_unless($team->isExpelledFrom($tournament), 404);

        $affectedMatches = TournamentMatch::query()
            ->where('tournament_id', $tournament->id)
            ->where('category_id', $category->id)
            ->where('walkover_team_id', $team->id)
            ->with(['homeTeam', 'awayTeam'])
            ->orderBy('scheduled_at')
            ->get();

        $pdfUploadsEnabled = Setting::sanctionPdfUploadsEnabled();

        return view('pages.tournaments.categories.teams.expulsion', compact(
            'tournament', 'category', 'team', 'affectedMatches', 'pdfUploadsEnabled'
        ));
    }

    /**
     * See SanctionController::updateResolutionPdf() -- same idea, for an
     * expulsion's resolution PDF instead of a sanction's.
     */
    public function updateResolutionPdf(ResolutionPdfRequest $request, Tournament $tournament, Category $category, Team $team, TeamExpulsionService $service): RedirectResponse
    {
        $this->authorize('update', $tournament);

        $this->assertTeamBelongs($tournament, $category, $team);

        if (! $team->isExpelledFrom($tournament)) {
            return back()->with('error', __('Este plantel no está expulsado de este torneo.'));
        }

        if (! Setting::sanctionPdfUploadsEnabled()) {
            return back()->with('error', __('La carga de PDF está deshabilitada.'));
        }

        $service->replaceResolutionPdf($team, $tournament, $request->file('resolution_pdf')->store('resoluciones', 'public'));

        return back()->with('status', __('PDF de la resolución actualizado.'));
    }

    /**
     * See SanctionController::destroyResolutionPdf() -- same idea, for an
     * expulsion's resolution PDF instead of a sanction's.
     */
    public function destroyResolutionPdf(Tournament $tournament, Category $category, Team $team, TeamExpulsionService $service): RedirectResponse
    {
        $this->authorize('update', $tournament);

        $this->assertTeamBelongs($tournament, $category, $team);

        if ($team->expulsionResolutionPdfPathFor($tournament) === null) {
            return back()->with('error', __('Este plantel no tiene un PDF de resolución para quitar.'));
        }

        $service->removeResolutionPdf($team, $tournament);

        return back()->with('status', __('PDF de la resolución eliminado.'));
    }
}
