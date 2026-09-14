<?php

namespace App\Http\Requests;

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
        // in the same step, not a separate visit.
        if ($player instanceof Player) {
            $candidateIds = $player->candidateTeamsForEnrollment()->pluck('id');
            $rules['team_ids'] = ['nullable', 'array'];
            $rules['team_ids.*'] = ['integer', Rule::in($candidateIds)];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $player = $this->route('player');

        if ($player instanceof Player) {
            $this->withEditTeamLinkValidator($validator, $player);

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

            if ($existingPlayer->birth_date === null) {
                $validator->errors()->add('document_number', __(
                    'Este jugador (:name) ya está registrado pero no tiene fecha de nacimiento cargada -- complétala primero desde su ficha para poder sumarlo a otro plantel.',
                    ['name' => $existingPlayer->full_name]
                ));

                return;
            }

            if (! $existingPlayer->ageEligibleForCategory($routeTeam->category)) {
                $validator->errors()->add('document_number', __(
                    'Por su fecha de nacimiento, :name no puede jugar en la categoría :category.',
                    ['name' => $existingPlayer->full_name, 'category' => $routeTeam->category->name]
                ));
            }
        });
    }

    /**
     * Editing a player can also link them to another plantel of the same
     * club in the same submission (see rules()'s 'team_ids') -- checked
     * against the birth_date THIS submission is setting, not whatever the
     * player had before, since completing that date is exactly what's
     * supposed to unlock this.
     */
    private function withEditTeamLinkValidator(Validator $validator, Player $player): void
    {
        $validator->after(function (Validator $validator) use ($player): void {
            $teamIds = collect($this->input('team_ids', []))->filter()->map(fn ($id) => (int) $id);
            if ($teamIds->isEmpty()) {
                return;
            }

            $birthDateInput = $this->input('birth_date') ?: $player->birth_date;
            if (! $birthDateInput) {
                $validator->errors()->add('team_ids', __('Carga primero la fecha de nacimiento.'));

                return;
            }

            $probe = new Player(['birth_date' => Carbon::parse($birthDateInput)]);
            $teams = Team::query()->whereKey($teamIds)->with('category')->get()->keyBy('id');

            foreach ($teamIds as $teamId) {
                $team = $teams->get($teamId);
                if (! $team) {
                    continue; // Already flagged by the 'in' rule above.
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
