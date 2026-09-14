<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Club;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * A new plantel (Team) created directly under a global Club -- see
 * docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md.
 * Unlike the legacy TeamRequest (unique per category only), identity here
 * is (club, category, group, name) -- decision #8: the same club can field
 * more than one squad in the very same category/group, told apart by
 * name (e.g. "A" / "B" because one roster doesn't fit every kid).
 */
class ClubTeamRequest extends FormRequest
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
        $team = $this->route('team');

        $categoryId = $team instanceof Team ? $team->category_id : $this->integer('category_id');
        $category = $categoryId ? Category::find($categoryId) : null;
        $usesGroups = $category?->uses_groups ?? false;

        return [
            'category_id' => [
                'required',
                Rule::exists('categories', 'id')->where('user_id', Auth::id())->whereNull('tournament_id'),
            ],
            'group_id' => [
                $usesGroups ? 'required' : 'prohibited',
                Rule::exists('groups', 'id')->where('category_id', $categoryId),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('teams')
                    ->where('club_id', $club instanceof Club ? $club->id : $team?->club_id)
                    ->where('category_id', $categoryId)
                    ->where('group_id', $this->input('group_id') ?: null)
                    ->ignore($team),
            ],
            'short_name' => ['nullable', 'string', 'max:10'],
        ];
    }
}
