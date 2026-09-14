<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Tournament;
use Illuminate\View\View;

/**
 * The public portal's page for one category (groups, teams, phase list) --
 * read-only, reachable without logging in (see routes/public.php).
 */
class PublicCategoryController extends Controller
{
    public function show(Tournament $tournament, Category $category): View
    {
        // $category is bound straight from its own id (not nested under
        // $tournament), so a mismatched pair in the URL must 404 instead of
        // silently showing a category from a different tournament. A
        // still-legacy category (pre-T02-01) is checked via its direct
        // `tournament_id`; a promoted catalog category via the
        // tournament_category pivot -- see
        // docs/plan-reestructuracion/02-unificacion-categorias-torneo.md.
        $belongsToTournament = $category->tournament_id
            ? $category->tournament_id === $tournament->id
            : $tournament->globalCategories()->whereKey($category->id)->exists();

        if (! $belongsToTournament) {
            abort(404);
        }

        $category->load(['groups' => fn ($query) => $query->withCount('teams')]);

        // A PROMOTED catalog category's own teams()/competitionPhases()
        // relations are NOT scoped to one tournament (it can be inscribed
        // into more than one) -- overriding them here with the
        // roster/phases THIS tournament actually has keeps the view's
        // existing $category->teams / $category->competitionPhases usage
        // correct without a template rewrite. A still-legacy category's own
        // relations are already correctly scoped (it only ever belonged to
        // this one tournament), so they're left as loaded. See
        // docs/plan-reestructuracion/02-unificacion-categorias-torneo.md
        // (T02-06).
        if (! $category->tournament_id) {
            $category->setRelation(
                'teams',
                $tournament->globalTeams()->where('teams.category_id', $category->id)->with('group')->get()
            );
            $category->setRelation(
                'competitionPhases',
                $category->competitionPhases()->where('tournament_id', $tournament->id)->withCount('matches')->get()
            );
        } else {
            $category->load(['teams.group', 'competitionPhases' => fn ($query) => $query->withCount('matches')]);
        }

        return view('pages.public.categories.show', compact('tournament', 'category'));
    }
}
