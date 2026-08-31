<?php

namespace App\Policies;

use App\Models\CompetitionPhase;
use App\Models\TournamentMatch;
use App\Models\User;

class TournamentMatchPolicy
{
    public function view(User $user, TournamentMatch $tournamentMatch): bool
    {
        return $user->id === $tournamentMatch->tournament->user_id;
    }

    public function create(User $user, CompetitionPhase $competitionPhase): bool
    {
        return $user->id === $competitionPhase->tournament->user_id;
    }

    public function update(User $user, TournamentMatch $tournamentMatch): bool
    {
        return $user->id === $tournamentMatch->tournament->user_id;
    }

    public function delete(User $user, TournamentMatch $tournamentMatch): bool
    {
        return $user->id === $tournamentMatch->tournament->user_id;
    }
}
