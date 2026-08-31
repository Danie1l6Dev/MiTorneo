<?php

namespace App\Enums;

enum CategoryStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activa',
            self::Inactive => 'Inactiva',
        };
    }
}
