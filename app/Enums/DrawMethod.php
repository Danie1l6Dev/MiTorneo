<?php

namespace App\Enums;

enum DrawMethod: string
{
    case Random = 'random';
    case Seeded = 'seeded';

    public function label(): string
    {
        return match ($this) {
            self::Random => 'Aleatorio',
            self::Seeded => 'Por posición',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Random => 'Los cruces se sortean al azar entre todos los clasificados.',
            self::Seeded => 'El mejor clasificado cruza con el peor, el segundo mejor con el segundo peor, y así sucesivamente. Con dos o más tablas, el mejor de una cruza con el peor de la otra.',
        };
    }
}
