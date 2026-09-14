<?php

namespace App\Http\Requests;

use App\Enums\CategoryStatus;
use App\Models\Category;
use App\Models\Tournament;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'uses_groups' => $this->boolean('uses_groups'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $category = $this->route('category');
        $tournament = $this->route('tournament');

        $tournamentId = match (true) {
            $category instanceof Category => $category->tournament_id,
            $tournament instanceof Tournament => $tournament->id,
            default => null,
        };

        // A legacy per-tournament category is unique by (tournament_id,
        // name); a global one (no $tournamentId here) is unique by
        // (user_id, name) instead -- see
        // docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md.
        $nameRule = $tournamentId
            ? Rule::unique('categories')->where('tournament_id', $tournamentId)->ignore($category)
            : Rule::unique('categories')->whereNull('tournament_id')->where('user_id', Auth::id())->ignore($category);

        $rules = [
            'name' => ['required', 'string', 'max:255', $nameRule],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::enum(CategoryStatus::class)],
            'uses_groups' => ['boolean'],
            'order' => ['nullable', 'integer', 'min:0'],
        ];

        if (! $tournamentId) {
            $rules['birth_year_from'] = ['nullable', 'integer', 'digits:4'];
            $rules['birth_year_to'] = ['nullable', 'integer', 'digits:4', 'gte:birth_year_from'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $category = $this->route('category');

            if (! $category instanceof Category) {
                return;
            }

            if (! $this->boolean('uses_groups') && $category->teams()->whereNotNull('group_id')->exists()) {
                $validator->errors()->add(
                    'uses_groups',
                    __('No puedes desactivar el uso de grupos mientras haya equipos asignados a un grupo.'),
                );
            }
        });
    }
}
