<?php

namespace App\Enums;

/**
 * How a player stopped being on a plantel -- the end of one line of their
 * history (PlayerTeamHistory).
 */
enum RosterEndReason: string
{
    /** Sacado del plantel (o del club). */
    case Removed = 'removed';
    /** Se fue a otro club. */
    case Transferred = 'transferred';
    /** Pasó a otro plantel del mismo club (por edad). */
    case Promoted = 'promoted';
    /** El plantel se eliminó. */
    case TeamDeleted = 'team_deleted';
    /** Reconstruido después del hecho: se sabe que ya no está, no cómo ni cuándo exactamente. */
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Removed => 'Retirado',
            self::Transferred => 'Transferido a otro club',
            self::Promoted => 'Promovido a otro plantel',
            self::TeamDeleted => 'Plantel eliminado',
            self::Unknown => 'Motivo desconocido',
        };
    }
}
