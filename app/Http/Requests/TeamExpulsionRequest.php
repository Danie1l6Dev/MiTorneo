<?php

namespace App\Http\Requests;

use App\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;

class TeamExpulsionRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:2000'],
        ];

        // See SanctionResolveRequest -- same admin-controlled switch, off
        // by default on a storage-limited server.
        if (Setting::sanctionPdfUploadsEnabled()) {
            $rules['resolution_pdf'] = ['nullable', 'file', 'mimes:pdf', 'max:5120'];
        }

        return $rules;
    }
}
