<?php

namespace App\Http\Requests;

use App\Models\Club;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

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

        // Picking starting categories only makes sense when
        // CREATING a club -- editing one only ever touches its name (see
        // ClubController::update()), planteles are managed from the
        // club's own show page from then on.
        if (! $club) {
            $ownedCategoryIds = Auth::user()->categories()->pluck('id');

            $rules['category_ids'] = ['nullable', 'array'];
            $rules['category_ids.*'] = ['integer', Rule::in($ownedCategoryIds)];
        }

        return $rules;
    }
}
