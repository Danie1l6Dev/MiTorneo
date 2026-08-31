<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use App\Models\Tournament;
use Illuminate\Http\RedirectResponse;
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

        $category->load(['competitionPhases', 'teams']);

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

    public function destroy(Category $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        $tournament = $category->tournament;

        $category->delete();

        return to_route('tournaments.show', $tournament);
    }
}
