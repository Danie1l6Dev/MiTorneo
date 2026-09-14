<?php

namespace App\Policies;

use App\Models\Club;
use App\Models\User;

class ClubPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Club $club): bool
    {
        return $user->id === $club->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Club $club): bool
    {
        return $user->id === $club->user_id;
    }

    public function delete(User $user, Club $club): bool
    {
        return $user->id === $club->user_id;
    }
}
