<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        $setting = Setting::current();

        return view('pages.admin.settings.edit', compact('setting'));
    }

    public function toggleSanctionPdfUploads(): RedirectResponse
    {
        $setting = Setting::current();
        $setting->sanction_pdf_uploads_enabled = ! $setting->sanction_pdf_uploads_enabled;
        $setting->save();

        return back()->with('status', __('Configuración actualizada.'));
    }
}
