<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CoachController;
use App\Http\Controllers\CompetitionPhaseController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\LeagueScheduleController;
use App\Http\Controllers\MatchResultController;
use App\Http\Controllers\PhaseAdvancementController;
use App\Http\Controllers\PhaseChampionController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\TournamentMatchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::resource('tournaments', TournamentController::class)->except('index');

    Route::resource('tournaments.categories', CategoryController::class)
        ->shallow()
        ->except('index');

    Route::patch('categories/{category}/toggle-status', [CategoryController::class, 'toggleStatus'])
        ->name('categories.toggle-status');

    Route::resource('categories.phases', CompetitionPhaseController::class)
        ->shallow()
        ->except('index');

    Route::resource('categories.groups', GroupController::class)
        ->shallow()
        ->except('index');

    Route::resource('categories.teams', TeamController::class)
        ->shallow()
        ->except(['index']);

    Route::resource('teams.players', PlayerController::class)
        ->shallow()
        ->except(['index', 'show', 'destroy']);

    Route::patch('players/{player}/toggle-active', [PlayerController::class, 'toggleActive'])
        ->name('players.toggle-active');

    Route::get('teams/{team}/coach/create', [CoachController::class, 'create'])
        ->name('teams.coach.create');

    Route::post('teams/{team}/coach', [CoachController::class, 'store'])
        ->name('teams.coach.store');

    Route::get('coaches/{coach}/edit', [CoachController::class, 'edit'])
        ->name('coaches.edit');

    Route::put('coaches/{coach}', [CoachController::class, 'update'])
        ->name('coaches.update');

    Route::patch('coaches/{coach}/toggle-active', [CoachController::class, 'toggleActive'])
        ->name('coaches.toggle-active');

    Route::resource('phases.matches', TournamentMatchController::class)
        ->shallow()
        ->only(['edit', 'update', 'destroy']);

    Route::post('phases/{phase}/schedule', [LeagueScheduleController::class, 'store'])
        ->name('phases.schedule.store');

    Route::delete('phases/{phase}/schedule', [LeagueScheduleController::class, 'destroy'])
        ->name('phases.schedule.destroy');

    Route::get('phases/{phase}/advance', [PhaseAdvancementController::class, 'create'])
        ->name('phases.advance.create');

    Route::post('phases/{phase}/advance', [PhaseAdvancementController::class, 'store'])
        ->name('phases.advance.store');

    Route::post('phases/{phase}/champion', [PhaseChampionController::class, 'store'])
        ->name('phases.champion.store');

    Route::delete('phases/{phase}/champion', [PhaseChampionController::class, 'destroy'])
        ->name('phases.champion.destroy');

    Route::patch('matches/{match}/result', [MatchResultController::class, 'update'])
        ->name('matches.result.update');

    Route::post('groups/{group}/teams', [GroupController::class, 'attachTeam'])
        ->name('groups.teams.attach');

    Route::delete('groups/{group}/teams/{team}', [GroupController::class, 'detachTeam'])
        ->name('groups.teams.detach');
});
