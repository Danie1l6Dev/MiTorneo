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
        // silently showing a category from a different tournament.
        if ($category->tournament_id !== $tournament->id) {
            abort(404);
        }

        $category->load([
            'groups' => fn ($query) => $query->withCount('teams'),
            'teams.group',
            'competitionPhases' => fn ($query) => $query->withCount('matches'),
        ]);

        return view('pages.public.categories.show', compact('tournament', 'category'));
    }
}
