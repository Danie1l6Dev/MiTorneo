<?php

namespace App\Enums;

/**
 * How far a category's player-statistics leaderboard (goals, assists, cards)
 * reaches across its phases. Deliberately just these two, not a picker over
 * individual phases: "league" always means every League-type phase the
 * category has (usually one), and "all" means every phase of any type --
 * neither hardcodes a phase name, so a category gaining new phase types
 * later (cuartos, octavos, another league, ...) needs no change here.
 */
enum StatisticsPhaseScope: string
{
    case League = 'league';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::League => 'Solo fase de liga',
            self::All => 'Toda la competición',
        };
    }
}
