<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $tournaments = Auth::user()->tournaments()->withCount(['globalCategories', 'globalTeams', 'matches'])->latest()->get();

        $clubsCountByTournament = DB::table('tournament_team')
            ->join('teams', 'teams.id', '=', 'tournament_team.team_id')
            ->whereIn('tournament_team.tournament_id', $tournaments->pluck('id'))
            ->whereNotNull('teams.club_id')
            ->selectRaw('tournament_team.tournament_id, count(distinct teams.club_id) as clubs_count')
            ->groupBy('tournament_team.tournament_id')
            ->pluck('clubs_count', 'tournament_team.tournament_id');

        $tournaments->each(function (Tournament $tournament) use ($clubsCountByTournament): void {
            $tournament->global_clubs_count = (int) ($clubsCountByTournament[$tournament->id] ?? 0);
        });

        $tournamentsCount = $tournaments->count();

        return view('dashboard', compact('tournaments', 'tournamentsCount'));
    }
}
