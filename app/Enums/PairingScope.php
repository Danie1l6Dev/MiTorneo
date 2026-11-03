<?php

namespace App\Enums;

/**
 * Who a team may be paired against when a cross round (or the phase after
 * one) is drawn: only a team from another group, or any other team.
 */
enum PairingScope: string
{
    case BetweenGroups = 'between_groups';
    case Anyone = 'anyone';

    public function label(): string
    {
        return match ($this) {
            self::BetweenGroups => 'Entre grupos',
            self::Anyone => 'Todos contra todos',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::BetweenGroups => 'Cada equipo se enfrenta a uno de otro grupo.',
            self::Anyone => 'Cualquier equipo puede enfrentarse a cualquier otro, sin importar su grupo.',
        };
    }
}
