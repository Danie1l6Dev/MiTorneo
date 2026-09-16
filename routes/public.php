<?php

use App\Http\Controllers\Public\PublicCategoryController;
use App\Http\Controllers\Public\PublicMatchController;
use App\Http\Controllers\Public\PublicPhaseController;
use App\Http\Controllers\Public\PublicSanctionController;
use App\Http\Controllers\Public\PublicTeamController;
use App\Http\Controllers\Public\PublicTournamentController;
use Illuminate\Support\Facades\Route;

// Deliberately outside the 'auth' group -- this is the read-only public
// portal an organizer shares with team owners/fans, so every route here
// must stay reachable without logging in. Each controller below only ever
// exposes a 'show'/'index' action: there is no create/update/delete route
// to guard, so a visitor simply has nothing to modify from this path.
Route::prefix('public/torneos')->name('public.tournaments.')->group(function () {
    Route::get('{tournament:slug}', [PublicTournamentController::class, 'show'])->name('show');

    Route::get('{tournament:slug}/categorias/{category}', [PublicCategoryController::class, 'show'])
        ->name('categories.show');

    Route::get('{tournament:slug}/fases/{phase}', [PublicPhaseController::class, 'show'])
        ->name('phases.show');

    Route::get('{tournament:slug}/partidos/{match}', [PublicMatchController::class, 'show'])
        ->name('matches.show');

    Route::get('{tournament:slug}/equipos/{team}', [PublicTeamController::class, 'show'])
        ->name('teams.show');

    Route::get('{tournament:slug}/sanciones', [PublicSanctionController::class, 'index'])
        ->name('sanctions.index');
});
