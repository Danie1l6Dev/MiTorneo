<?php

namespace App\Http\Requests;

use App\Models\Venue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VenueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Venue::$name is stored uppercased with single spaces, so compare it
        // that way too: "cancha  boscán" must collide with "CANCHA BOSCÁN".
        if (is_string($this->input('name'))) {
            $this->merge(['name' => mb_strtoupper(trim((string) preg_replace('/\s+/', ' ', $this->input('name'))))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $venue = $this->route('venue');

        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('venues', 'name')
                    ->where('user_id', $this->user()?->id)
                    ->ignore($venue instanceof Venue ? $venue->id : null),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => __('Ya tienes una cancha registrada con ese nombre.'),
        ];
    }
}
