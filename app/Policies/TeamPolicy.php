<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    public function view(User $user, Team $team): bool
    {
        return $user->id === $team->tournament->user_id;
    }

    public function create(User $user, Category $category): bool
    {
        return $user->id === $category->tournament->user_id;
    }

    public function update(User $user, Team $team): bool
    {
        return $user->id === $team->tournament->user_id;
    }

    public function delete(User $user, Team $team): bool
    {
        return $user->id === $team->tournament->user_id;
    }
}
