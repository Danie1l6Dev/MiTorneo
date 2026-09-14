<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $tournaments = Auth::user()->tournaments()->withCount(['globalCategories', 'globalTeams', 'matches'])->latest()->get();
        $tournamentsCount = $tournaments->count();

        return view('dashboard', compact('tournaments', 'tournamentsCount'));
    }
}
