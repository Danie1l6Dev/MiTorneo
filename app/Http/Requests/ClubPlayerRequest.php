<?php

namespace App\Http\Requests;

use App\Enums\Gender;
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
            'gender' => ['nullable', Rule::enum(Gender::class)],
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
            $club = $this->route('club');

            $teamIds = collect($this->input('team_ids', []))->filter()->map(fn ($id) => (int) $id);
            if ($teamIds->isEmpty()) {
                return; // Already flagged by the 'required'/'exists' rules above.
            }

            $documentNumber = $this->input('document_number');
            $existingPlayer = $documentNumber ? Player::findForOrganizer($documentNumber, Auth::id()) : null;

            if ($existingPlayer && $club instanceof Club && ($blockedBy = $existingPlayer->blocksJoiningClub($club)) !== null) {
                $validator->errors()->add('document_number', __(
                    'Este jugador (:name) pertenece a otro club (:club). Hay que desactivarlo ahí primero para poder agregarlo a este club.',
                    ['name' => $existingPlayer->full_name, 'club' => $blockedBy->name]
                ));

                return;
            }

            // birth_date is required by this form's own rules above, so
            // whatever was typed here -- correcting an existing player's
            // stored value, or setting a brand-new one's -- is always what
            // gets checked and saved; see PlayerController::storeForClub().
            $birthDate = Carbon::parse($this->input('birth_date'));
            $gender = $this->filled('gender') ? $this->enum('gender', Gender::class) : $existingPlayer?->gender;
            $probe = new Player(['birth_date' => $birthDate, 'gender' => $gender]);

            $teams = Team::query()->whereKey($teamIds)->with('category')->get()->keyBy('id');

            foreach ($teamIds as $teamId) {
                $team = $teams->get($teamId);
                if (! $team) {
                    continue; // Already flagged by the 'exists' rule above.
                }

                // Silently skipped, not an error: the checkbox for a
                // plantel the player is already on is rendered
                // checked+disabled precisely so it's never meant to be
                // resubmitted, but a stray resubmission still reaches here
                // sometimes -- same non-error treatment PlayerController::
                // update() already gives this exact case, and what
                // storeForClub()'s own array_diff() already assumes when it
                // dedupes $teamIds against the player's current plantels.
                if ($existingPlayer && ($existingPlayer->team_id === $teamId || $existingPlayer->teams()->whereKey($teamId)->exists())) {
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
