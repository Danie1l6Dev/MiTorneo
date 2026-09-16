<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ClubController;
use App\Http\Controllers\CoachController;
use App\Http\Controllers\CompetitionPhaseController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\LeagueScheduleController;
use App\Http\Controllers\MatchEventController;
use App\Http\Controllers\MatchLineupController;
use App\Http\Controllers\MatchResultController;
use App\Http\Controllers\PhaseAdvancementController;
use App\Http\Controllers\PhaseChampionController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\RefereeController;
use App\Http\Controllers\SanctionController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamExpulsionController;
use App\Http\Controllers\TournamentCategoryController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\TournamentMatchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::resource('tournaments', TournamentController::class)->except('index');

    Route::patch('tournaments/{tournament}/regenerate-slug', [TournamentController::class, 'regenerateSlug'])
        ->name('tournaments.regenerate-slug');

    // "Inscripción" of a tournament from the global catalog -- see
    // docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md
    // (T01-24/T01-25). Deliberately separate from the legacy
    // tournaments.categories.create/store above (per-tournament category
    // creation, still the only path that can host phases/matches today).
    Route::get('tournaments/{tournament}/global-categories/create', [TournamentCategoryController::class, 'create'])
        ->name('tournaments.global-categories.create');

    Route::post('tournaments/{tournament}/global-categories', [TournamentCategoryController::class, 'store'])
        ->name('tournaments.global-categories.store');

    Route::delete('tournaments/{tournament}/global-categories/{category}', [TournamentCategoryController::class, 'destroy'])
        ->name('tournaments.global-categories.destroy');

    Route::get('tournaments/{tournament}/global-categories/{category}/teams', [TournamentCategoryController::class, 'editTeams'])
        ->name('tournaments.global-categories.teams.edit');

    Route::put('tournaments/{tournament}/global-categories/{category}/teams', [TournamentCategoryController::class, 'updateTeams'])
        ->name('tournaments.global-categories.teams.update');

    // This tournament's own edition of a catalog category -- its groups and
    // phases, which belong to THIS tournament, not to the category's global
    // template (that lives at categories.show, reached from the sidebar
    // catalog instead). See TournamentCategoryController::show().
    Route::get('tournaments/{tournament}/categories/{category}', [TournamentCategoryController::class, 'show'])
        ->name('tournaments.categories.show');

    // Same as categories.phases.create/store, but with the tournament given
    // explicitly in the URL -- the only way to create a first phase for a
    // catalog category that's inscribed in more than one tournament (see
    // CompetitionPhaseController::createForTournament()).
    Route::get('tournaments/{tournament}/categories/{category}/phases/create', [CompetitionPhaseController::class, 'createForTournament'])
        ->name('tournaments.categories.phases.create');

    Route::post('tournaments/{tournament}/categories/{category}/phases', [CompetitionPhaseController::class, 'storeForTournament'])
        ->name('tournaments.categories.phases.store');

    // Expelling a plantel from this tournament's edition of a category --
    // see TeamExpulsionService for what happens to its remaining matches.
    // Reversible via the destroy route below, same as declaring a champion.
    Route::get('tournaments/{tournament}/categories/{category}/teams/{team}/expel', [TeamExpulsionController::class, 'create'])
        ->name('tournaments.categories.teams.expel.create');

    Route::post('tournaments/{tournament}/categories/{category}/teams/{team}/expel', [TeamExpulsionController::class, 'store'])
        ->name('tournaments.categories.teams.expel.store');

    Route::delete('tournaments/{tournament}/categories/{category}/teams/{team}/expel', [TeamExpulsionController::class, 'destroy'])
        ->name('tournaments.categories.teams.expel.destroy');

    // Referees are global to the organizer, not nested under a tournament --
    // this is a standalone top-level resource, same as tournaments.
    Route::resource('referees', RefereeController::class)->except('destroy');

    // Categories are global to the organizer now (see
    // docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md)
    // -- this flat resource covers index/create/store/show/edit/update/
    // destroy for that global catalog. A tournament never creates its own
    // category anymore -- it only picks from this catalog, via
    // tournaments.global-categories.* above (see
    // docs/plan-reestructuracion/02-unificacion-categorias-torneo.md,
    // T02-04).
    Route::resource('categories', CategoryController::class);

    Route::patch('categories/{category}/toggle-status', [CategoryController::class, 'toggleStatus'])
        ->name('categories.toggle-status');

    // Clubs are global to the organizer too -- see
    // docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md.
    // A plantel (Team) is created directly under a club, picking one of
    // the organizer's own global categories (+group if it uses them).
    Route::resource('clubs', ClubController::class);

    Route::get('clubs/{club}/teams/create', [TeamController::class, 'createForClub'])
        ->name('clubs.teams.create');

    Route::post('clubs/{club}/teams', [TeamController::class, 'storeForClub'])
        ->name('clubs.teams.store');

    // Enroll a player into one or more of the club's own planteles at
    // once -- the checkbox-per-category flow, see ClubPlayerRequest.
    Route::get('clubs/{club}/players/create', [PlayerController::class, 'createForClub'])
        ->name('clubs.players.create');

    Route::post('clubs/{club}/players', [PlayerController::class, 'storeForClub'])
        ->name('clubs.players.store');

    // Removes every plantel this player has at $club -- see
    // PlayerController::destroyFromClub() for what that actually involves
    // (blocked if there's real match history to lose).
    Route::delete('clubs/{club}/players/{player}', [PlayerController::class, 'destroyFromClub'])
        ->name('clubs.players.destroy');

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

    Route::delete('players/{player}/teams/{team}', [PlayerController::class, 'detachTeam'])
        ->name('players.teams.destroy');

    // Moves a single player out of $team once its category no longer fits
    // them (age rule) into whichever older category in the same club does
    // -- see Player::promotionCandidateTeams()/promoteFromTeam().
    Route::get('teams/{team}/players/{player}/promote', [PlayerController::class, 'promoteForm'])
        ->name('teams.players.promote.create');

    Route::post('teams/{team}/players/{player}/promote', [PlayerController::class, 'promote'])
        ->name('teams.players.promote.store');

    // Bulk version: promotes every unambiguous case in $team's roster at
    // once, see PlayerController::promoteEligible().
    Route::post('teams/{team}/players/promote-eligible', [PlayerController::class, 'promoteEligible'])
        ->name('teams.players.promote-eligible');

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

    Route::patch('matches/{match}/reset', [TournamentMatchController::class, 'reset'])
        ->name('matches.reset');

    // No edit/update -- events are intentionally not editable in place; a
    // user who wants a different type/subject deletes the event and
    // registers the correct one, so there's only ever one path (creation)
    // that has to stay consistent with the goal/assist/card rules below.
    Route::resource('matches.events', MatchEventController::class)
        ->shallow()
        ->except(['index', 'show', 'edit', 'update']);

    Route::post('matches/{match}/events/batch', [MatchEventController::class, 'storeBatch'])
        ->name('matches.events.batch-store');

    // Who's actually called up to play a match, searched from across the
    // whole club (not just this one category's plantel) -- see
    // MatchLineup's docblock. No index/show/edit/update: the search panel
    // only ever adds (store) or removes (destroy) one entry at a time.
    Route::resource('matches.lineups', MatchLineupController::class)
        ->shallow()
        ->only(['store', 'destroy']);

    // Sanctions are only ever created by SanctionService, from card events
    // -- no create/store/destroy routes, this resource is read + resolve
    // only. How many fechas have been served is always computed from the
    // team's own match calendar (see Sanction::matchesServedCount()),
    // never a manual step, so there's no "mark served" route either.
    Route::resource('sanctions', SanctionController::class)->only(['index', 'show']);

    Route::patch('sanctions/{sanction}/resolve', [SanctionController::class, 'resolve'])
        ->name('sanctions.resolve');

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
