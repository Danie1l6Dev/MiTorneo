<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CompetitionPhaseController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\TournamentMatchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::resource('tournaments', TournamentController::class);

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

    Route::patch('groups/{group}/teams', [GroupController::class, 'updateTeams'])
        ->name('groups.teams.update');
});
