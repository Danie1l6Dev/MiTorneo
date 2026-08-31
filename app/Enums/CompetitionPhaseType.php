<?php

namespace App\Enums;

/**
 * Describes the format a phase competes under. Purely informational for now:
 * fixture generation and standings logic per type are introduced in a later phase.
 */
enum CompetitionPhaseType: string
{
    case League = 'league';
    case GroupStage = 'group_stage';
    case Knockout = 'knockout';
    case Semifinal = 'semifinal';
    case Final = 'final';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::League => 'Liga',
            self::GroupStage => 'Fase de grupos',
            self::Knockout => 'Eliminación directa',
            self::Semifinal => 'Semifinales',
            self::Final => 'Final',
            self::Custom => 'Personalizada',
        };
    }
}
