<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\Group;
use App\Models\User;

class GroupPolicy
{
    public function view(User $user, Group $group): bool
    {
        return $user->id === $group->ownerId();
    }

    public function create(User $user, Category $category): bool
    {
        return $user->id === $category->ownerId();
    }

    public function update(User $user, Group $group): bool
    {
        return $user->id === $group->ownerId();
    }

    public function delete(User $user, Group $group): bool
    {
        return $user->id === $group->ownerId();
    }
}
