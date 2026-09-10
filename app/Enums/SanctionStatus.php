<?php

namespace App\Enums;

/**
 * Whether a Sanction still awaits a Comité Directivo resolution (how many
 * fechas, and -- for a DT -- how much of a fine) or already has one on
 * record. A double_yellow Sanction is created directly as Resolved (rule 1
 * fixes it at 1 fecha automatically, no committee decision needed); a
 * red_card Sanction is always created Pending, since its duration must never
 * be assumed -- see Sanction::classifyCardTally().
 */
enum SanctionStatus: string
{
    case Pending = 'pending';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente de resolución',
            self::Resolved => 'Resuelta',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Resolved => 'green',
        };
    }
}
