<?php

namespace App\Policies;

use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\User;

class GroupPolicy
{
    public function view(User $user, Group $group): bool
    {
        return $user->id === $group->tournament->user_id;
    }

    public function create(User $user, CompetitionPhase $competitionPhase): bool
    {
        return $user->id === $competitionPhase->tournament->user_id;
    }

    public function update(User $user, Group $group): bool
    {
        return $user->id === $group->tournament->user_id;
    }

    public function delete(User $user, Group $group): bool
    {
        return $user->id === $group->tournament->user_id;
    }
}
