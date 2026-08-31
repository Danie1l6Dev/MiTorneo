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

    public function create(User $user, Category $category): bool
    {
        return $user->id === $category->tournament->user_id;
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
