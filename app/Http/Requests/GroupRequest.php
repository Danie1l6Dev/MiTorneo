<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Group;
use App\Models\Tournament;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GroupRequest extends FormRequest
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
        $group = $this->route('group');
        $category = $this->route('category');

        $categoryId = match (true) {
            $group instanceof Group => $group->category_id,
            $category instanceof Category => $category->id,
            default => null,
        };

        // Names are unique per tournament and category: two tournaments that
        // include the same category each have their own "Grupo A".
        $tournamentId = match (true) {
            $group instanceof Group => $group->tournament_id,
            $this->route('tournament') instanceof Tournament => $this->route('tournament')->id,
            $category instanceof Category => $category->tournament_id ?? $category->tournaments()->value('tournaments.id'),
            default => null,
        };

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('groups')
                    ->where('category_id', $categoryId)
                    ->where('tournament_id', $tournamentId)
                    ->ignore($group),
            ],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
