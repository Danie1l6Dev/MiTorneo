<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use Illuminate\View\View;

class TournamentController extends Controller
{
    public function index(): View
    {
        $tournaments = Tournament::with('user')->latest()->get();

        return view('pages.admin.tournaments.index', compact('tournaments'));
    }
}
