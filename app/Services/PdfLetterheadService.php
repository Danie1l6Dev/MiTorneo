<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\File;

/**
 * The letterhead images every exported PDF (standings, match results) is
 * printed with: the generic MiTorneo one by default, Faudis' LIFUTGUA one
 * for his account only (see User::usesMunicipalLetterhead()) -- that
 * federation branding/NIT would be meaningless (or actively wrong) on anyone
 * else's export. Images are inlined as base64 data URIs so dompdf never
 * needs remote fetching enabled.
 */
class PdfLetterheadService
{
    /**
     * @return array<string, string>
     */
    public function forUser(User $user): array
    {
        if (! $user->usesMunicipalLetterhead()) {
            return [
                'letterhead' => 'default',
                'appLogo' => $this->imageAsDataUri('mitorneo-logo.svg'),
            ];
        }

        return [
            'letterhead' => 'municipal',
            'lifutguaLogo' => $this->imageAsDataUri('lifutgua.jpg'),
            'difutbolLogo' => $this->imageAsDataUri('difutbol.jpg'),
            'wordmark' => $this->imageAsDataUri('lifutgua-wordmark.png'),
            'signature' => $this->imageAsDataUri('coordinador-firma.jpg'),
        ];
    }

    private function imageAsDataUri(string $fileName): string
    {
        $path = resource_path("images/standings-pdf/{$fileName}");
        $mimeType = match (pathinfo($fileName, PATHINFO_EXTENSION)) {
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            default => 'image/jpeg',
        };

        return "data:{$mimeType};base64,".base64_encode(File::get($path));
    }
}
