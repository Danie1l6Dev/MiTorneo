<?php

namespace App\Http\Controllers;

use App\Http\Requests\VenueRequest;
use App\Models\Venue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class VenueController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Venue::class);

        $venues = Auth::user()->venues()->withCount('matches')->orderBy('name')->get();

        return view('pages.venues.index', compact('venues'));
    }

    public function create(): View
    {
        $this->authorize('create', Venue::class);

        return view('pages.venues.create');
    }

    public function store(VenueRequest $request): RedirectResponse
    {
        $this->authorize('create', Venue::class);

        Auth::user()->venues()->create($request->validated());

        return to_route('venues.index')->with('status', __('Cancha registrada correctamente.'));
    }

    public function edit(Venue $venue): View
    {
        $this->authorize('update', $venue);

        $venue->loadCount('matches');

        return view('pages.venues.edit', compact('venue'));
    }

    public function update(VenueRequest $request, Venue $venue): RedirectResponse
    {
        $this->authorize('update', $venue);

        $venue->update($request->validated());

        return to_route('venues.index')->with('status', __('Cancha actualizada correctamente.'));
    }

    /**
     * Matches keep their date and time when their cancha is deleted -- the
     * venue_id foreign key is nullOnDelete -- they just go back to "sin
     * cancha", which is why the confirmation spells out how many are affected.
     */
    public function destroy(Venue $venue): RedirectResponse
    {
        $this->authorize('delete', $venue);

        $venue->delete();

        return to_route('venues.index')->with('status', __('Cancha eliminada correctamente.'));
    }
}
