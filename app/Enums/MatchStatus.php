<?php

namespace App\Enums;

enum MatchStatus: string
{
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Finished = 'finished';
    case Postponed = 'postponed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Programado',
            self::InProgress => 'En juego',
            self::Finished => 'Finalizado',
            self::Postponed => 'Postergado',
            self::Cancelled => 'Cancelado',
        };
    }
}
