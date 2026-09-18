<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public demo account is shared by every visitor, so it must not be able
 * to change its own credentials, enrol 2FA/passkeys or delete itself (all of
 * which live under settings/profile, settings/security and Fortify's user/*).
 * Appearance settings stay available.
 */
class BlockDemoAccountChanges
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isDemo() && $request->is('settings/profile', 'settings/security', 'user/*')) {
            abort_unless($request->isMethod('GET'), 403);

            return redirect()->route('dashboard')->with('status', __('La cuenta demo no puede modificar sus credenciales.'));
        }

        return $next($request);
    }
}
