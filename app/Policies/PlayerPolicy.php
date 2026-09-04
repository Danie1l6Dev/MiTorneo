<?php

namespace App\Policies;

use App\Models\Player;
use App\Models\Team;
use App\Models\User;

class PlayerPolicy
{
    public function view(User $user, Player $player): bool
    {
        return $user->id === $player->team->tournament->user_id;
    }

    public function create(User $user, Team $team): bool
    {
        return $user->id === $team->tournament->user_id;
    }

    public function update(User $user, Player $player): bool
    {
        return $user->id === $player->team->tournament->user_id;
    }
}
