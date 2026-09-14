<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The public portal's landing page for one tournament -- read-only, reachable
 * without logging in (see routes/public.php). Deliberately has no other
 * action: nothing on this path ever creates, updates, or deletes anything.
 */
class PublicTournamentController extends Controller
{
    public function show(Tournament $tournament): View
    {
        // A still-legacy tournament (pre-T02-01, its categories still have
        // their own $tournament_id) keeps reading them the old way. A
        // promoted tournament's categories()/teams() HasMany are
        // permanently empty (every category is a catalog category now, see
        // docs/plan-reestructuracion/02-unificacion-categorias-torneo.md),
        // so it reads the catalog + tournament_category/tournament_team
        // pivots instead. A tournament is always entirely one or the
        // other -- T02-01 promotes all of a tournament's categories in one
        // transaction, never half.
        $isLegacy = $tournament->categories()->exists();

        if ($isLegacy) {
            $tournament->load(['categories' => fn ($query) => $query->withCount(['teams', 'groups'])]);
            $tournament->loadCount(['categories', 'teams', 'matches']);
            $globalTeamCounts = collect();
        } else {
            $tournament->load(['globalCategories' => fn ($query) => $query->withCount('groups')]);
            $tournament->loadCount(['globalCategories', 'globalTeams', 'matches']);

            $globalTeamCounts = DB::table('tournament_team')
                ->join('teams', 'teams.id', '=', 'tournament_team.team_id')
                ->where('tournament_team.tournament_id', $tournament->id)
                ->selectRaw('teams.category_id, count(*) as aggregate')
                ->groupBy('teams.category_id')
                ->pluck('aggregate', 'teams.category_id');
        }

        return view('pages.public.tournaments.show', compact('tournament', 'globalTeamCounts', 'isLegacy'));
    }
}
