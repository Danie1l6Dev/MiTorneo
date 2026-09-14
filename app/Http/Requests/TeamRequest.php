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

        // A global team (created under a Club -- see ClubTeamRequest) is
        // told apart from another squad of the SAME club by name, but two
        // DIFFERENT clubs are free to both use "A" in the same category;
        // a legacy per-tournament team never has a club_id, so it keeps
        // the original category-only scoping.
        $nameRule = $team instanceof Team && $team->club_id
            ? Rule::unique('teams')->where('club_id', $team->club_id)->where('category_id', $categoryId)->ignore($team)
            : Rule::unique('teams')->where('category_id', $categoryId)->ignore($team);

        return [
            'name' => ['required', 'string', 'max:255', $nameRule],
            'short_name' => ['nullable', 'string', 'max:10'],
            'group_id' => [
                $usesGroups ? 'required' : 'prohibited',
                Rule::exists('groups', 'id')->where('category_id', $categoryId),
            ],
        ];
    }
}
