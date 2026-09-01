<?php

namespace App\Enums;

/**
 * Describes the format a phase competes under. Purely informational for now:
 * fixture generation and standings logic per type are introduced in a later phase.
 */
enum CompetitionPhaseType: string
{
    case League = 'league';
    case Knockout = 'knockout';
    case Semifinal = 'semifinal';
    case Final = 'final';

    public function label(): string
    {
        return match ($this) {
            self::League => 'Liga',
            self::Knockout => 'Eliminación directa',
            self::Semifinal => 'Semifinales',
            self::Final => 'Final',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::League => 'green',
            self::Knockout, self::Semifinal, self::Final => 'amber',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::League => 'calendar-days',
            self::Knockout => 'bolt',
            self::Semifinal, self::Final => 'flag',
        };
    }
}
