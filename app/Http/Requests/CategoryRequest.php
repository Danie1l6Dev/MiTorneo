<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Tournament;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
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
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
