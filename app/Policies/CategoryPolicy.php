<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\Tournament;
use App\Models\User;

class CategoryPolicy
{
    public function view(User $user, Category $category): bool
    {
        return $user->id === $category->tournament->user_id;
    }

    public function create(User $user, Tournament $tournament): bool
    {
        return $user->id === $tournament->user_id;
    }

    public function update(User $user, Category $category): bool
    {
        return $user->id === $category->tournament->user_id;
    }

    public function delete(User $user, Category $category): bool
    {
        return $user->id === $category->tournament->user_id;
    }
}
