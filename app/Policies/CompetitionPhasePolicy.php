<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\User;

class CompetitionPhasePolicy
{
    public function view(User $user, CompetitionPhase $competitionPhase): bool
    {
        return $user->id === $competitionPhase->tournament->user_id;
    }

    /**
     * A category not yet attached to any tournament -- neither the legacy
     * direct $tournament_id (pre-T02-01) nor the tournament_category pivot
     * (a promoted catalog category) -- can't host a phase at all, denied
     * outright rather than crashing when CompetitionPhaseController tries
     * to resolve which tournament the phase is for. See
     * docs/plan-reestructuracion/02-unificacion-categorias-torneo.md
     * (T02-03).
     */
    public function create(User $user, Category $category): bool
    {
        $hasTournament = $category->tournament_id !== null || $category->tournaments()->exists();

        return $user->id === $category->ownerId() && $hasTournament;
    }

    public function update(User $user, CompetitionPhase $competitionPhase): bool
    {
        return $user->id === $competitionPhase->tournament->user_id;
    }

    public function delete(User $user, CompetitionPhase $competitionPhase): bool
    {
        return $user->id === $competitionPhase->tournament->user_id;
    }
}
