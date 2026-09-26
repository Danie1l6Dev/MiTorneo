<?php

namespace App\Enums;

/**
 * How a player came to be on a plantel -- the start of one line of their
 * history (PlayerTeamHistory).
 */
enum RosterStartReason: string
{
    /** First registered in the system, directly on this plantel. */
    case Registered = 'registered';
    /** Sumado a otro plantel del mismo club (categoría adicional). */
    case Added = 'added';
    /** Llegó desde otro club. */
    case Transferred = 'transferred';
    /** Pasó de un plantel a otro del mismo club (por edad). */
    case Promoted = 'promoted';
    /** Reconstruido después del hecho, sin fecha real -- ver PlayerHistoryBackfillService. */
    case Estimated = 'estimated';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Alta',
            self::Added => 'Plantel adicional',
            self::Transferred => 'Transferido desde otro club',
            self::Promoted => 'Promovido',
            self::Estimated => 'Estimado',
        };
    }
}
