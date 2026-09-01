<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CompetitionPhaseController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\LeagueScheduleController;
use App\Http\Controllers\MatchResultController;
use App\Http\Controllers\PhaseAdvancementController;
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
        ->except(['index', 'show']);

    Route::resource('phases.matches', TournamentMatchController::class)
        ->shallow()
        ->except(['index', 'show']);

    Route::post('phases/{phase}/schedule', [LeagueScheduleController::class, 'store'])
        ->name('phases.schedule.store');

    Route::delete('phases/{phase}/schedule', [LeagueScheduleController::class, 'destroy'])
        ->name('phases.schedule.destroy');

    Route::get('phases/{phase}/advance', [PhaseAdvancementController::class, 'create'])
        ->name('phases.advance.create');

    Route::post('phases/{phase}/advance', [PhaseAdvancementController::class, 'store'])
        ->name('phases.advance.store');

    Route::patch('matches/{match}/result', [MatchResultController::class, 'update'])
        ->name('matches.result.update');

    Route::post('groups/{group}/teams', [GroupController::class, 'attachTeam'])
        ->name('groups.teams.attach');

    Route::delete('groups/{group}/teams/{team}', [GroupController::class, 'detachTeam'])
        ->name('groups.teams.detach');
});
