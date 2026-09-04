<?php

namespace App\Policies;

use App\Models\Coach;
use App\Models\Team;
use App\Models\User;

class CoachPolicy
{
    public function view(User $user, Coach $coach): bool
    {
        return $user->id === $coach->team->tournament->user_id;
    }

    public function create(User $user, Team $team): bool
    {
        return $user->id === $team->tournament->user_id;
    }

    public function update(User $user, Coach $coach): bool
    {
        return $user->id === $coach->team->tournament->user_id;
    }
}
