<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        $users = User::withCount('tournaments')->latest()->get();

        return view('pages.admin.users.index', compact('users'));
    }

    public function toggleActive(User $user): RedirectResponse
    {
        if ($user->id === Auth::id()) {
            return back()->with('error', __('No puedes desactivar tu propia cuenta.'));
        }

        $user->is_active = ! $user->is_active;
        $user->save();

        return back()->with('status', __('Estado del usuario actualizado.'));
    }
}
