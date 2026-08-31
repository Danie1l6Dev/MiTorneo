<?php

namespace App\Http\Requests;

use App\Enums\CategoryStatus;
use App\Models\Category;
use App\Models\Tournament;
use Illuminate\Foundation\Http\FormRequest;
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

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories')
                    ->where('tournament_id', $tournamentId)
                    ->ignore($category),
            ],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::enum(CategoryStatus::class)],
            'uses_groups' => ['boolean'],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
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
