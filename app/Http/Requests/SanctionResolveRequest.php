<?php

namespace App\Http\Requests;

use App\Models\Sanction;
use App\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SanctionResolveRequest extends FormRequest
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
        $rules = [
            'matches_banned' => ['required', 'integer', 'min:1', 'max:50'],
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
            'fine_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ];

        // The upload field only exists in the form (and is only ever
        // accepted) while an admin has the feature switched on -- see
        // Setting::sanctionPdfUploadsEnabled(). While it's off,
        // resolution_notes stays the only way to record why.
        if (Setting::sanctionPdfUploadsEnabled()) {
            $rules['resolution_pdf'] = ['nullable', 'file', 'mimes:pdf', 'max:5120'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $sanction = $this->route('sanction');

            // A fine only ever applies to a DT -- see Sanction's docblock.
            if ($sanction instanceof Sanction && $sanction->coach_id === null && $this->filled('fine_amount')) {
                $validator->errors()->add('fine_amount', __('Solo se puede registrar una multa económica para un director técnico.'));
            }
        });
    }
}
