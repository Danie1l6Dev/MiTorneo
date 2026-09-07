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

    /**
     * The plural noun for a competition-wide leaderboard row's count (e.g.
     * "8 goles") -- a separate method from label() because label() is
     * singular and used for a single event ("Gol"), while a leaderboard
     * count is always plural and needs its own gender agreement ("goles
     * registrados" vs "asistencias registradas").
     */
    public function statisticNoun(): string
    {
        return match ($this) {
            self::Goal => 'goles',
            self::Assist => 'asistencias',
            self::YellowCard => 'amarillas',
            self::RedCard => 'rojas',
        };
    }

    /**
     * Heading for a category's leaderboard of this type (e.g. "Goleadores").
     */
    public function leaderboardTitle(): string
    {
        return match ($this) {
            self::Goal => 'Goleadores',
            self::Assist => 'Asistidores',
            self::YellowCard => 'Tarjetas amarillas',
            self::RedCard => 'Tarjetas rojas',
        };
    }

    /**
     * Empty-state copy for a leaderboard with no matching rows yet -- written
     * out per type instead of interpolated from statisticNoun() so Spanish
     * gender agreement ("registrados" vs "registradas") stays correct.
     */
    public function emptyStatisticsMessage(): string
    {
        return match ($this) {
            self::Goal => 'Ningún jugador tiene goles registrados para este filtro.',
            self::Assist => 'Ningún jugador tiene asistencias registradas para este filtro.',
            self::YellowCard => 'Ningún jugador tiene tarjetas amarillas registradas para este filtro.',
            self::RedCard => 'Ningún jugador tiene tarjetas rojas registradas para este filtro.',
        };
    }
}
