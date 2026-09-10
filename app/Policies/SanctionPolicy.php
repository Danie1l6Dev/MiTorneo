<?php

namespace App\Policies;

use App\Models\Sanction;
use App\Models\User;

class SanctionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Sanction $sanction): bool
    {
        return $user->id === $sanction->team->tournament->user_id;
    }

    public function resolve(User $user, Sanction $sanction): bool
    {
        return $user->id === $sanction->team->tournament->user_id;
    }
}
