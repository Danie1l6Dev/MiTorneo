<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DemoLoginController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless(config('demo.enabled'), 404);

        $demo = User::query()->where('email', User::DEMO_EMAIL)->where('is_active', true)->firstOrFail();

        Auth::login($demo);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
