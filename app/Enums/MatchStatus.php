<?php

namespace App\Enums;

enum MatchStatus: string
{
    case Scheduled = 'scheduled';
    case Finished = 'finished';
    case Postponed = 'postponed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Programado',
            self::Finished => 'Finalizado',
            self::Postponed => 'Postergado',
            self::Cancelled => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Scheduled => 'zinc',
            self::Finished => 'green',
            self::Postponed => 'amber',
            self::Cancelled => 'red',
        };
    }
}
