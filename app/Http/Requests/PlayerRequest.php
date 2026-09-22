<?php

namespace App\Http\Requests;

use App\Enums\Gender;
use App\Models\Player;
use App\Models\Team;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PlayerRequest extends FormRequest
{
    /**
     * The only knob to turn if the tournament's numbering rules ever need a
     * wider (or narrower) range -- nothing else about jersey numbers assumes
     * a specific ceiling.
     */
    public const MAX_JERSEY_NUMBER = 99;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $player = $this->route('player');
        $routeTeam = $this->route('team');

        $team = match (true) {
            $routeTeam instanceof Team => $routeTeam,
            $player instanceof Player => $player->team,
            default => null,
        };

        $teamId = $team?->id;

        // A global team (see docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md)
        // finds-or-links an existing player by document_number instead of
        // always creating a new row -- see PlayerController::storeForTeam().
        // jersey_number there is scoped to the player_team pivot, not the
        // legacy players.jersey_number column, since the same player can
        // have a different dorsal per plantel.
        if ($team && ! $team->tournament_id && ! $player) {
            return [
                'full_name' => ['required', 'string', 'max:255'],
                'document_number' => ['nullable', 'string', 'max:30'],
                'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
                'gender' => ['nullable', Rule::enum(Gender::class)],
                'jersey_number' => [
                    'nullable',
                    'integer',
                    'min:1',
                    'max:'.self::MAX_JERSEY_NUMBER,
                    // A dorsal can be taken either the "old" way
                    // (players.jersey_number, for this team's first-ever
                    // links) or via the player_team pivot (2nd+ links) --
                    // Rule::unique alone can only ever check one table, so
                    // this checks both explicitly.
                    function (string $attribute, mixed $value, Closure $fail) use ($teamId): void {
                        $taken = Player::query()->where('team_id', $teamId)->where('jersey_number', $value)->exists()
                            || DB::table('player_team')->where('team_id', $teamId)->where('jersey_number', $value)->exists();

                        if ($taken) {
                            $fail(__('Ya hay un jugador de este equipo con ese dorsal.'));
                        }
                    },
                ],
            ];
        }

        $rules = [
            'full_name' => ['required', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            // Both optional for now -- a team can register a player before
            // their document/dorsal is settled. The 'unique' rule below never
            // even runs for a blank value: 'nullable' short-circuits the rest
            // of a field's rules once it's null, which is exactly what lets
            // two teammates both leave theirs blank without colliding with
            // each other.
            'document_number' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('players', 'document_number')
                    ->where('team_id', $teamId)
                    ->where('is_active', true)
                    ->ignore($player),
            ],
            'jersey_number' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.self::MAX_JERSEY_NUMBER,
                Rule::unique('players', 'jersey_number')
                    ->where('team_id', $teamId)
                    ->where('is_active', true)
                    ->ignore($player),
            ],
        ];

        // Editing an existing player also offers "add them to another
        // plantel of the same club" (see Player::candidateTeamsForEnrollment())
        // -- exactly what closes the gap this feature was built for: a
        // backfilled player finally getting their birth_date filled in
        // right here should be able to go straight into another category
        // in the same step, not a separate visit. Deliberately NOT
        // validated here: whether a picked team is a real candidate and
        // age-eligible depends on the birth_date THIS submission is
        // setting, and a bad pick must never roll back the birth_date/other
        // fields that were otherwise fine -- see
        // PlayerController::update(), which checks and links them itself
        // after saving the rest.
        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $player = $this->route('player');

        if ($player instanceof Player) {
            return;
        }

        $routeTeam = $this->route('team');

        if (! $routeTeam instanceof Team || $routeTeam->tournament_id) {
            return;
        }

        // Global-team flow only, and only when a document was given -- with
        // none, there's nothing to match against and this is necessarily a
        // brand-new player's first team, which never needs the age check
        // (see Player::ageEligibleForCategory()'s docblock).
        $documentNumber = $this->input('document_number');
        if (! $documentNumber) {
            return;
        }

        $validator->after(function (Validator $validator) use ($routeTeam, $documentNumber): void {
            $existingPlayer = Player::findForOrganizer($documentNumber, Auth::id());

            if (! $existingPlayer) {
                return; // Brand-new player -- their first team, always allowed.
            }

            if ($existingPlayer->teams()->whereKey($routeTeam->id)->exists() || $existingPlayer->team_id === $routeTeam->id) {
                $validator->errors()->add('document_number', __('Ese jugador ya está en este plantel.'));

                return;
            }

            if ($routeTeam->club && ($blockedBy = $existingPlayer->blocksJoiningClub($routeTeam->club)) !== null) {
                $validator->errors()->add('document_number', __(
                    'Este jugador (:name) pertenece a otro club (:club). Desactivalo ahí primero para poder agregarlo a este club.',
                    ['name' => $existingPlayer->full_name, 'club' => $blockedBy->name]
                ));

                return;
            }

            // Whatever this submission itself typed into birth_date/gender
            // takes priority over the existing player's stored values -- see
            // PlayerController::storeForTeam(). That's what lets completing
            // (or correcting) either one happen right here, in the same step
            // as adding them to a new plantel, instead of a separate trip to
            // their edit page first.
            $birthDate = $this->filled('birth_date') ? Carbon::parse($this->input('birth_date')) : $existingPlayer->birth_date;

            if ($birthDate === null) {
                $validator->errors()->add('birth_date', __(
                    'Hace falta la fecha de nacimiento de :name para poder sumarlo a otro plantel.',
                    ['name' => $existingPlayer->full_name]
                ));

                return;
            }

            $gender = $this->filled('gender') ? $this->enum('gender', Gender::class) : $existingPlayer->gender;
            $probe = new Player(['birth_date' => $birthDate, 'gender' => $gender]);

            if (! $probe->ageEligibleForCategory($routeTeam->category)) {
                $validator->errors()->add('document_number', __(
                    'Por su fecha de nacimiento, :name no puede jugar en la categoría :category.',
                    ['name' => $existingPlayer->full_name, 'category' => $routeTeam->category->name]
                ));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'jersey_number.unique' => __('Ya hay un jugador activo de este equipo con ese dorsal.'),
            'document_number.unique' => __('Ya hay un jugador activo de este equipo con ese documento.'),
        ];
    }
}
