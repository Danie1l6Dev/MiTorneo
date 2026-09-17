<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by SanctionController::updateResolutionPdf() and
 * TeamExpulsionController::updateResolutionPdf() -- both just replace
 * whatever resolution PDF is on file with a new upload, same
 * mimes/size limits as the original resolve/expel forms
 * (SanctionResolveRequest/TeamExpulsionRequest).
 */
class ResolutionPdfRequest extends FormRequest
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
            'resolution_pdf' => ['required', 'file', 'mimes:pdf', 'max:5120'],
        ];
    }
}
