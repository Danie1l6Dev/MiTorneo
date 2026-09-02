<?php

namespace App\Services;

use App\Enums\CompetitionPhaseType;
use App\Models\Category;

/**
 * Central authority for which competition-phase configurations are
 * sportingly valid, independent of any specific category's rules: whether a
 * category can start with a given phase type, and how many qualifiers a
 * fixed-size phase (semifinal, final) needs from each standings table
 * feeding it. Kept free of controller/request concerns so both the create
 * form and the advancement flow validate against the same rules.
 */
class PhaseEligibilityService
{
    /**
     * Total qualifiers a Semifinal or Final phase always needs, regardless
     * of how many standings tables (groups) feed it -- League and Knockout
     * have no fixed target, the user chooses how many qualify.
     */
    private const FIXED_QUALIFIER_TARGETS = [
        'semifinal' => 4,
        'final' => 2,
    ];

    public function isPowerOfTwo(int $n): bool
    {
        return $n >= 2 && ($n & ($n - 1)) === 0;
    }

    /**
     * Null for League/Knockout (the user picks the qualifier count); the
     * fixed total a Semifinal or Final phase always needs otherwise.
     */
    public function fixedQualifierTarget(CompetitionPhaseType $type): ?int
    {
        return self::FIXED_QUALIFIER_TARGETS[$type->value] ?? null;
    }

    /**
     * How many qualifiers to take from each of $tableCount standings tables
     * so their total hits a fixed $target; null when that target can't be
     * split evenly across the tables available (e.g. a 4-team target across
     * 3 tables), meaning the phase can't be created as configured.
     */
    public function perTableCountForTarget(int $target, int $tableCount): ?int
    {
        if ($tableCount < 1 || $target % $tableCount !== 0) {
            return null;
        }

        return intdiv($target, $tableCount);
    }

    /**
     * A category's first phase is created directly (no standings to draw
     * qualifiers from yet), so only one may ever exist -- every phase after
     * it is created from an already-finished phase's qualifiers instead.
     */
    public function canCreateFirstPhase(Category $category): bool
    {
        return $category->competitionPhases()->doesntExist();
    }

    /**
     * Which phase types a category can start with, and why not for the
     * rest. A category using groups can only start with an independent
     * league per group: a knockout format only makes sporting sense once
     * those leagues have produced qualifiers to seed a bracket with. A
     * category without groups can also start directly with a knockout, but
     * only when its current team count is already a valid bracket size (a
     * power of two, at least 2) -- otherwise some teams would have no
     * opponent and no legal bracket can be drawn.
     *
     * @return array<int, array{type: CompetitionPhaseType, available: bool, reason: string|null}>
     */
    public function firstPhaseTypeOptions(Category $category): array
    {
        $teamCount = $category->teams()->count();

        $knockoutReason = match (true) {
            $category->uses_groups => (string) __('Esta categoría usa grupos: la primera fase debe ser una liga por grupos.'),
            ! $this->isPowerOfTwo($teamCount) => (string) __(
                'Se necesita una cantidad de equipos que sea potencia de 2 (2, 4, 8, 16...); esta categoría tiene :count.',
                ['count' => $teamCount]
            ),
            default => null,
        };

        return [
            ['type' => CompetitionPhaseType::League, 'available' => true, 'reason' => null],
            ['type' => CompetitionPhaseType::Knockout, 'available' => $knockoutReason === null, 'reason' => $knockoutReason],
        ];
    }

    public function firstPhaseTypeAllowed(Category $category, CompetitionPhaseType $type): bool
    {
        foreach ($this->firstPhaseTypeOptions($category) as $option) {
            if ($option['type'] === $type) {
                return $option['available'];
            }
        }

        return false;
    }
}
