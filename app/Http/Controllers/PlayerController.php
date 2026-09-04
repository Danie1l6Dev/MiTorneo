<?php

namespace App\Http\Controllers;

use App\Http\Requests\PlayerRequest;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PlayerController extends Controller
{
    public function create(Team $team): View
    {
        $this->authorize('create', [Player::class, $team]);

        return view('pages.players.create', compact('team'));
    }

    public function store(PlayerRequest $request, Team $team): RedirectResponse
    {
        $this->authorize('create', [Player::class, $team]);

        $team->players()->create($request->validated());

        return to_route('teams.show', $team)->with('status', __('Jugador agregado correctamente.'));
    }

    public function edit(Player $player): View
    {
        $this->authorize('update', $player);

        return view('pages.players.edit', compact('player'));
    }

    public function update(PlayerRequest $request, Player $player): RedirectResponse
    {
        $this->authorize('update', $player);

        $player->update($request->validated());

        return to_route('teams.show', $player->team)->with('status', __('Jugador actualizado correctamente.'));
    }

    /**
     * Reactivating a player re-checks the same "unique among active
     * teammates" rule PlayerRequest enforces on create/edit -- their number
     * or document could have been taken by someone else while they were
     * inactive.
     */
    public function toggleActive(Player $player): RedirectResponse
    {
        $this->authorize('update', $player);

        if (! $player->is_active) {
            $conflict = Player::query()
                ->where('team_id', $player->team_id)
                ->where('is_active', true)
                ->where(fn ($query) => $query
                    ->where('jersey_number', $player->jersey_number)
                    ->orWhere('document_number', $player->document_number)
                )
                ->exists();

            if ($conflict) {
                return back()->with('error', __(
                    'No se puede reactivar a :name: su dorsal o documento ya está en uso por otro jugador activo de este equipo.',
                    ['name' => $player->full_name]
                ));
            }
        }

        $player->is_active = ! $player->is_active;
        $player->save();

        return back()->with('status', __('Estado del jugador actualizado.'));
    }
}
