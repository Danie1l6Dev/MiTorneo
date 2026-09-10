<?php

namespace App\Enums;

/**
 * What triggered a Sanction -- always one of the two card-based origins the
 * club's disciplinary rules recognize. Kept separate from MatchEventType:
 * a Sanction is never itself a card, it's the disciplinary consequence a
 * card (or, for DoubleYellow, a pair of them) can lead to. See Sanction's
 * own docblock for how this differs from a plain MatchEvent card row.
 */
enum SanctionType: string
{
    case DoubleYellow = 'double_yellow';
    case RedCard = 'red_card';

    public function label(): string
    {
        return match ($this) {
            self::DoubleYellow => 'Doble amarilla',
            self::RedCard => 'Roja directa',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DoubleYellow => 'amber',
            self::RedCard => 'red',
        };
    }
}
