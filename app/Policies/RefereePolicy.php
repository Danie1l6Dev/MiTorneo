<?php

namespace App\Policies;

use App\Models\Referee;
use App\Models\User;

class RefereePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Referee $referee): bool
    {
        return $user->id === $referee->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Referee $referee): bool
    {
        return $user->id === $referee->user_id;
    }
}
