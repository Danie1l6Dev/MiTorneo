<?php

namespace App\Http\Requests;

use App\Models\Club;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Transferring a player to another club: which club, which of its planteles
 * (the age rule applies to each), when, and an optional note and new dorsal.
 * The heavy lifting -- closing the old lines, opening the new ones -- is
 * PlayerRosterService::moveToClub().
 */
class PlayerTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $player = $this->route('player');

        return $player instanceof Player && ($this->user()?->can('update', $player) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $player = $this->route('player');

        // A transfer can't be earlier than the moment they joined the plantel they're leaving.
        $latestStart = $player instanceof Player
            ? $player->teamHistory()->whereNull('ended_on')->max('started_on')
            : null;

        return [
            'club_id' => ['required', Rule::exists('clubs', 'id')->where('user_id', $this->user()?->id)],
            'team_ids' => ['required', 'array', 'min:1'],
            'team_ids.*' => ['integer', 'distinct'],
            'date' => array_filter(['required', 'date', 'before_or_equal:today', $latestStart ? 'after_or_equal:'.$latestStart : null]),
            'notes' => ['nullable', 'string', 'max:500'],
            'jersey_number' => ['nullable', 'integer', 'min:1', 'max:'.PlayerRequest::MAX_JERSEY_NUMBER],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $player = $this->route('player');

            if ($validator->errors()->isNotEmpty() || ! $player instanceof Player) {
                return;
            }

            if ($player->birth_date === null) {
                $validator->errors()->add('team_ids', __('Completa primero la fecha de nacimiento del jugador: hace falta para saber en qué categorías puede jugar.'));

                return;
            }

            $club = Club::query()->findOrFail($this->integer('club_id'));

            if (in_array($club->id, $player->clubIds(), true)) {
                $validator->errors()->add('club_id', __('El jugador ya está en ese club. Para sumarlo a otro plantel del mismo club usa la edición de su ficha.'));

                return;
            }

            $teams = Team::query()->with('category')->whereIn('id', $this->input('team_ids'))->get();

            if ($teams->count() !== count($this->input('team_ids')) || $teams->contains(fn (Team $team): bool => $team->club_id !== $club->id || $team->tournament_id !== null)) {
                $validator->errors()->add('team_ids', __('Alguno de los planteles elegidos no es de ese club.'));

                return;
            }

            $tooOld = $teams->first(fn (Team $team): bool => ! $player->ageEligibleForCategory($team->category));

            if ($tooOld !== null) {
                $validator->errors()->add('team_ids', __('El jugador no cumple la edad de :category.', ['category' => $tooOld->category->name]));

                return;
            }

            $jersey = $this->jerseyNumber($player);

            if ($jersey !== null) {
                $primary = Team::sortedByCategoryAge($teams)->first();

                $taken = Player::query()->where('team_id', $primary->id)->where('is_active', true)->where('jersey_number', $jersey)->whereKeyNot($player->id)->exists()
                    || DB::table('player_team')->where('team_id', $primary->id)->where('jersey_number', $jersey)->where('player_id', '!=', $player->id)->exists();

                if ($taken) {
                    $validator->errors()->add('jersey_number', __('Ya hay un jugador de ese plantel con el dorsal :number. Elige otro.', ['number' => $jersey]));
                }
            }
        });
    }

    /**
     * The dorsal for the new plantel: the one typed, else the one they had.
     */
    public function jerseyNumber(Player $player): ?int
    {
        return filled($this->input('jersey_number')) ? (int) $this->input('jersey_number') : $player->jersey_number;
    }
}
