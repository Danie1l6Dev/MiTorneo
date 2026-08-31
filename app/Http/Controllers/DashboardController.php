<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $tournaments = Auth::user()->tournaments()->latest()->take(5)->get();
        $tournamentsCount = Auth::user()->tournaments()->count();

        return view('dashboard', compact('tournaments', 'tournamentsCount'));
    }
}
