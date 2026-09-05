<?php

namespace App\Policies;

use App\Models\MatchEvent;
use App\Models\TournamentMatch;
use App\Models\User;

class MatchEventPolicy
{
    public function view(User $user, MatchEvent $matchEvent): bool
    {
        return $user->id === $matchEvent->match->tournament->user_id;
    }

    public function create(User $user, TournamentMatch $tournamentMatch): bool
    {
        return $user->id === $tournamentMatch->tournament->user_id;
    }

    public function delete(User $user, MatchEvent $matchEvent): bool
    {
        return $user->id === $matchEvent->match->tournament->user_id;
    }
}
