<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CoachRequest extends FormRequest
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
            'full_name' => ['required', 'string', 'max:255'],
            // Optional for now -- a team can register a coach before their
            // document is settled.
            'document_number' => ['nullable', 'string', 'max:30'],
        ];
    }
}
