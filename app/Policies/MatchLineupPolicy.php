<?php

namespace App\Policies;

use App\Models\MatchLineup;
use App\Models\TournamentMatch;
use App\Models\User;

class MatchLineupPolicy
{
    public function create(User $user, TournamentMatch $tournamentMatch): bool
    {
        return $user->id === $tournamentMatch->tournament->user_id;
    }

    public function delete(User $user, MatchLineup $matchLineup): bool
    {
        return $user->id === $matchLineup->match->tournament->user_id;
    }
}
