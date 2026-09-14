<?php

namespace App\Http\Requests;

use App\Models\Club;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The club-level "agregar jugador" form: pick one or more of the club's
 * own planteles to enroll a player in at once -- see
 * docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md
 * (T01-11, T01-27) and PlayerController::storeForClub(). birth_date is
 * required here (unlike the single-team PlayerRequest flow) because the
 * whole point of this form is picking categories by age -- there's
 * nothing to gate the checkboxes on without it.
 */
class ClubPlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $club = $this->route('club');

        return [
            'full_name' => ['required', 'string', 'max:255'],
            'document_number' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['required', 'date', 'before_or_equal:today'],
            'team_ids' => ['required', 'array', 'min:1'],
            'team_ids.*' => [
                'integer',
                Rule::exists('teams', 'id')->where('club_id', $club instanceof Club ? $club->id : null),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $teamIds = collect($this->input('team_ids', []))->filter()->map(fn ($id) => (int) $id);
            if ($teamIds->isEmpty()) {
                return; // Already flagged by the 'required'/'exists' rules above.
            }

            $documentNumber = $this->input('document_number');
            $existingPlayer = $documentNumber ? Player::findForOrganizer($documentNumber, Auth::id()) : null;

            if ($existingPlayer && $existingPlayer->birth_date === null) {
                $validator->errors()->add('document_number', __(
                    'Este jugador (:name) ya está registrado pero no tiene fecha de nacimiento cargada -- complétala primero desde su ficha para poder sumarlo a otro plantel.',
                    ['name' => $existingPlayer->full_name]
                ));

                return;
            }

            // The existing player's own birth_date is authoritative once
            // they're found -- what got typed into the form is ignored for
            // them (same as the single-team flow), it only drives the
            // check for a genuinely new player.
            $birthDate = $existingPlayer?->birth_date ?? Carbon::parse($this->input('birth_date'));
            $probe = new Player(['birth_date' => $birthDate]);

            $teams = Team::query()->whereKey($teamIds)->with('category')->get()->keyBy('id');

            foreach ($teamIds as $teamId) {
                $team = $teams->get($teamId);
                if (! $team) {
                    continue; // Already flagged by the 'exists' rule above.
                }

                if ($existingPlayer && ($existingPlayer->team_id === $teamId || $existingPlayer->teams()->whereKey($teamId)->exists())) {
                    $validator->errors()->add('team_ids', __('Ya está en el plantel :team.', ['team' => $team->name]));

                    continue;
                }

                if (! $probe->ageEligibleForCategory($team->category)) {
                    $validator->errors()->add('team_ids', __(
                        'Por su fecha de nacimiento, no puede jugar en :category (:team).',
                        ['category' => $team->category->name, 'team' => $team->name]
                    ));
                }
            }
        });
    }
}
