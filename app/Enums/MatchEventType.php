<?php

namespace App\Enums;

enum MatchEventType: string
{
    case Goal = 'goal';
    case Assist = 'assist';
    case YellowCard = 'yellow_card';
    case RedCard = 'red_card';

    public function label(): string
    {
        return match ($this) {
            self::Goal => 'Gol',
            self::Assist => 'Asistencia',
            self::YellowCard => 'Tarjeta amarilla',
            self::RedCard => 'Tarjeta roja',
        };
    }

    /**
     * Blade component name from anodyne/blade-tabler-icons (MIT-licensed
     * Tabler Icons, outline/stroke style -- matches the minimalist look the
     * rest of the app already uses via Flux's bundled Heroicons). Yellow and
     * red cards intentionally share the same icon: they're the same shape in
     * real life too, told apart only by color() below.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Goal => 'tabler-ball-football',
            self::Assist => 'tabler-shoe',
            self::YellowCard, self::RedCard => 'tabler-rectangle-vertical-filled',
        };
    }

    /**
     * Short code for the accumulated "2x G, 1x A, 1x TA" summary in
     * x-ui.match-event-row -- never shown standalone, always alongside the
     * icon/color which already disambiguates yellow vs. red.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Goal => 'G',
            self::Assist => 'A',
            self::YellowCard => 'TA',
            self::RedCard => 'TR',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Goal => 'green',
            self::Assist => 'cyan',
            self::YellowCard => 'amber',
            self::RedCard => 'red',
        };
    }

    /**
     * A full, literal Tailwind class for icon() below -- never built via
     * string interpolation (e.g. "text-{$this->color()}-500"): Tailwind's
     * build only generates CSS for a utility it finds as literal contiguous
     * text in a scanned file, so an assembled class name would silently
     * produce no styling (same reasoning already documented on
     * CompetitionPhaseController::bracketSizeTokens()).
     */
    public function iconColorClass(): string
    {
        return match ($this) {
            self::Goal => 'text-green-500',
            self::Assist => 'text-cyan-500',
            self::YellowCard => 'text-amber-500',
            self::RedCard => 'text-red-500',
        };
    }
}
