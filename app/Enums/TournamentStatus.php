<?php

namespace App\Enums;

enum TournamentStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Finished = 'finished';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Active => 'En curso',
            self::Finished => 'Finalizado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::Active => 'green',
            self::Finished => 'cyan',
        };
    }
}
