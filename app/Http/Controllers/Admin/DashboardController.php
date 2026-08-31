<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $usersCount = User::count();
        $activeUsersCount = User::where('is_active', true)->count();
        $adminsCount = User::where('role', UserRole::Admin)->count();
        $tournamentsCount = Tournament::count();

        return view('pages.admin.dashboard', compact(
            'usersCount',
            'activeUsersCount',
            'adminsCount',
            'tournamentsCount',
        ));
    }
}
