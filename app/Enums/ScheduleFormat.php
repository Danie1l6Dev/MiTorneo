<?php

namespace App\Enums;

enum ScheduleFormat: string
{
    case SingleRound = 'single_round';
    case HomeAndAway = 'home_and_away';

    public function label(): string
    {
        return match ($this) {
            self::SingleRound => 'Una sola vuelta',
            self::HomeAndAway => 'Ida y vuelta',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SingleRound => 'Cada equipo enfrenta a cada rival una vez.',
            self::HomeAndAway => 'Cada equipo enfrenta a cada rival dos veces, alternando local y visitante.',
        };
    }
}
