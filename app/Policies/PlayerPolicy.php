<?php

namespace App\Policies;

use App\Models\Club;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;

class PlayerPolicy
{
    public function view(User $user, Player $player): bool
    {
        return $user->id === $player->team->ownerId();
    }

    public function create(User $user, Team|Club $owner): bool
    {
        return $user->id === $owner->ownerId();
    }

    public function update(User $user, Player $player): bool
    {
        return $user->id === $player->team->ownerId();
    }
}
