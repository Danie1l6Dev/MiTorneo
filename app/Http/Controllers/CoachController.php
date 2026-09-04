<?php

namespace App\Http\Controllers;

use App\Http\Requests\CoachRequest;
use App\Models\Coach;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CoachController extends Controller
{
    public function create(Team $team): View|RedirectResponse
    {
        $this->authorize('create', [Coach::class, $team]);

        if ($redirect = $this->guardSingleActiveCoach($team)) {
            return $redirect;
        }

        return view('pages.coaches.create', compact('team'));
    }

    public function store(CoachRequest $request, Team $team): RedirectResponse
    {
        $this->authorize('create', [Coach::class, $team]);

        if ($redirect = $this->guardSingleActiveCoach($team)) {
            return $redirect;
        }

        $team->coaches()->create($request->validated());

        return to_route('teams.show', $team)->with('status', __('Director técnico registrado correctamente.'));
    }

    public function edit(Coach $coach): View
    {
        $this->authorize('update', $coach);

        return view('pages.coaches.edit', compact('coach'));
    }

    public function update(CoachRequest $request, Coach $coach): RedirectResponse
    {
        $this->authorize('update', $coach);

        $coach->update($request->validated());

        return to_route('teams.show', $coach->team)->with('status', __('Director técnico actualizado correctamente.'));
    }

    /**
     * Reactivating an old coach record re-checks the "at most one active per
     * team" rule -- the team could have registered a different active coach
     * since this one was deactivated.
     */
    public function toggleActive(Coach $coach): RedirectResponse
    {
        $this->authorize('update', $coach);

        if (! $coach->is_active && $coach->team->coach !== null) {
            return back()->with('error', __(
                'No se puede reactivar: el equipo ya tiene un director técnico activo.'
            ));
        }

        $coach->is_active = ! $coach->is_active;
        $coach->save();

        return to_route('teams.show', $coach->team)->with('status', __('Estado del director técnico actualizado.'));
    }

    private function guardSingleActiveCoach(Team $team): ?RedirectResponse
    {
        if ($team->coach !== null) {
            return to_route('teams.show', $team)->with('error', __(
                'Este equipo ya tiene un director técnico activo. Desactívalo primero si quieres registrar otro.'
            ));
        }

        return null;
    }
}
