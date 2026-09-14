<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Category $category): bool
    {
        return $user->id === $category->ownerId();
    }

    /**
     * Any signed-in user may create a category in their own global catalog
     * -- a tournament never creates its own category anymore, see
     * docs/plan-reestructuracion/02-unificacion-categorias-torneo.md.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Category $category): bool
    {
        return $user->id === $category->ownerId();
    }

    public function delete(User $user, Category $category): bool
    {
        return $user->id === $category->ownerId();
    }
}
