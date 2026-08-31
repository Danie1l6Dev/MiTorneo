<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeamRequest extends FormRequest
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
        $team = $this->route('team');
        $routeCategory = $this->route('category');

        $category = match (true) {
            $routeCategory instanceof Category => $routeCategory,
            $team instanceof Team => $team->category,
            default => null,
        };

        $categoryId = $category?->id;
        $usesGroups = $category instanceof Category && $category->uses_groups;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('teams')
                    ->where('category_id', $categoryId)
                    ->ignore($team),
            ],
            'short_name' => ['nullable', 'string', 'max:10'],
            'group_id' => [
                $usesGroups ? 'required' : 'prohibited',
                Rule::exists('groups', 'id')->where('category_id', $categoryId),
            ],
        ];
    }
}
