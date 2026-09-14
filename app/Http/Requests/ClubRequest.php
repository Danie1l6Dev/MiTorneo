<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Club;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ClubRequest extends FormRequest
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

        $rules = [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('clubs')
                    ->where('user_id', Auth::id())
                    ->ignore($club instanceof Club ? $club->id : null),
            ],
        ];

        // Picking starting categories/groups only makes sense when
        // CREATING a club -- editing one only ever touches its name (see
        // ClubController::update()), planteles are managed from the
        // club's own show page from then on.
        if (! $club) {
            $ownedCategoryIds = Auth::user()->categories()->pluck('id');

            $rules['category_ids'] = ['nullable', 'array'];
            $rules['category_ids.*'] = ['integer', Rule::in($ownedCategoryIds)];
            $rules['group_selections'] = ['nullable', 'array'];
            $rules['group_selections.*'] = ['array'];
            $rules['group_selections.*.*'] = ['integer'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        if ($this->route('club')) {
            return;
        }

        $validator->after(function (Validator $validator): void {
            $selections = (array) $this->input('group_selections', []);
            if ($selections === []) {
                return;
            }

            $categories = Category::query()
                ->whereIn('id', array_keys($selections))
                ->where('user_id', Auth::id())
                ->whereNull('tournament_id')
                ->with('groups')
                ->get()
                ->keyBy('id');

            foreach ($selections as $categoryId => $groupIds) {
                $category = $categories->get((int) $categoryId);

                if (! $category || ! $category->uses_groups) {
                    $validator->errors()->add('group_selections', __('Categoría inválida.'));

                    continue;
                }

                $validGroupIds = $category->groups->pluck('id')->all();
                foreach ((array) $groupIds as $groupId) {
                    if (! in_array((int) $groupId, $validGroupIds, true)) {
                        $validator->errors()->add('group_selections', __('Ese grupo no pertenece a :category.', ['category' => $category->name]));
                    }
                }
            }
        });
    }
}
