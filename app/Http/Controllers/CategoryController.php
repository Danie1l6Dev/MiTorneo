<?php

namespace App\Http\Controllers;

use App\Enums\CategoryStatus;
use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use App\Models\Tournament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function create(Tournament $tournament): View
    {
        $this->authorize('create', [Category::class, $tournament]);

        return view('pages.categories.create', compact('tournament'));
    }

    public function store(CategoryRequest $request, Tournament $tournament): RedirectResponse
    {
        $this->authorize('create', [Category::class, $tournament]);

        $category = $tournament->categories()->create($request->validated());

        return to_route('categories.show', $category);
    }

    public function show(Category $category): View
    {
        $this->authorize('view', $category);

        $category->load(['groups', 'teams', 'competitionPhases']);

        return view('pages.categories.show', compact('category'));
    }

    public function edit(Category $category): View
    {
        $this->authorize('update', $category);

        return view('pages.categories.edit', compact('category'));
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $this->authorize('update', $category);

        $category->update($request->validated());

        return to_route('categories.show', $category);
    }

    public function toggleStatus(Category $category): RedirectResponse
    {
        $this->authorize('update', $category);

        $category->status = $category->status === CategoryStatus::Active
            ? CategoryStatus::Inactive
            : CategoryStatus::Active;

        $category->save();

        return back()->with('status', __('Estado de la categoría actualizado.'));
    }

    public function destroy(Request $request, Category $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        $hasDependencies = $category->teams()->exists()
            || $category->groups()->exists()
            || $category->competitionPhases()->exists();

        if ($hasDependencies && ! $request->boolean('force')) {
            return back()->with('error', __(
                'Esta categoría tiene equipos, grupos o fases asociadas. Confirma la eliminación definitiva si deseas borrar todo su contenido.'
            ));
        }

        $tournament = $category->tournament;

        $category->delete();

        return to_route('tournaments.show', $tournament);
    }
}
