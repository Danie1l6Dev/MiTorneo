<?php

namespace App\Http\Requests;

use App\Enums\TournamentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TournamentRequest extends FormRequest
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
            'description' => ['nullable', 'string'],
            'season' => ['nullable', 'string', 'max:20'],
            'status' => ['required', Rule::enum(TournamentStatus::class)],
        ];
    }
}
