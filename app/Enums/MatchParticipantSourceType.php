<?php

namespace App\Enums;

/**
 * Describes where one side of a match gets its team from. This lets a match be
 * created before its participants are actually known — e.g. a semifinal whose
 * home side is "the winner of match #12" or "1st place of Group A" — without
 * resolving that reference into a concrete team yet.
 *
 * Resolving these references (reading a finished match's winner, or reading a
 * StandingsService table for a given position) is not implemented yet: this
 * enum only names the possible sources so the schema is ready for it.
 */
enum MatchParticipantSourceType: string
{
    case Team = 'team';
    case MatchWinner = 'match_winner';
    case StandingPosition = 'standing_position';

    public function label(): string
    {
        return match ($this) {
            self::Team => 'Equipo determinado',
            self::MatchWinner => 'Ganador de otro partido',
            self::StandingPosition => 'Posición de una tabla',
        };
    }
}
