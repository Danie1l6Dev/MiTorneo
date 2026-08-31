<?php

namespace App\Http\Requests;

use App\Enums\CompetitionPhaseType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdvancePhaseRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CompetitionPhaseType::class)],
            'qualifiers_per_table' => ['required', 'integer', 'min:1'],
        ];
    }
}
