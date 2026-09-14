<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\Club;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    public function view(User $user, Team $team): bool
    {
        return $user->id === $team->ownerId();
    }

    public function create(User $user, Category|Club $owner): bool
    {
        return $user->id === $owner->ownerId();
    }

    public function update(User $user, Team $team): bool
    {
        return $user->id === $team->ownerId();
    }

    public function delete(User $user, Team $team): bool
    {
        return $user->id === $team->ownerId();
    }
}
