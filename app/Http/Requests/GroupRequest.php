<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Group;
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

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('groups')
                    ->where('category_id', $categoryId)
                    ->ignore($group),
            ],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
