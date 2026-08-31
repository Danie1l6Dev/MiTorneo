<?php

namespace App\Enums;

enum MatchParticipantSide: string
{
    case Home = 'home';
    case Away = 'away';

    public function label(): string
    {
        return match ($this) {
            self::Home => 'Local',
            self::Away => 'Visitante',
        };
    }
}
