<?php

namespace App\Policies;

use App\Models\Coach;
use App\Models\Team;
use App\Models\User;

class CoachPolicy
{
    public function view(User $user, Coach $coach): bool
    {
        return $user->id === $coach->team->ownerId();
    }

    public function create(User $user, Team $team): bool
    {
        return $user->id === $team->ownerId();
    }

    public function update(User $user, Coach $coach): bool
    {
        return $user->id === $coach->team->ownerId();
    }
}
