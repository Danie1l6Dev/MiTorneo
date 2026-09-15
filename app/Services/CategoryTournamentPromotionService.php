<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Club;
use App\Models\Group;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * T02-01 of docs/plan-reestructuracion/02-unificacion-categorias-torneo.md.
 *
 * Unlike GlobalCatalogBackfillService (Tema 01), this never creates a
 * parallel row -- it PROMOTES each legacy per-tournament Category/Group/Team
 * in place (same id, just `tournament_id` flips to null and, for Category,
 * `user_id` gets set). Every row that references them by id
 * (`competition_phases`, `matches`, `match_events`, `match_participants`,
 * `sanctions`, `league_schedules`, `players.team_id`, `player_team`,
 * `competition_phase_team`) keeps working untouched, because the id it
 * points at never changes.
 *
 * Categories are never merged across tournaments (unlike Tema 01's
 * backfill): two legacy categories sharing a name for the same organizer
 * each get promoted as their OWN global row, the second one disambiguated
 * by appending its tournament's name -- fusing them would mean reparenting
 * one tournament's phases/matches onto the other's category id, which risks
 * mixing two independent competitions' results. See the plan doc for why a
 * real fusion case hasn't shown up in production data.
 */
class CategoryTournamentPromotionService
{
    /**
     * @param  array<int, array<string, string>>  $clubNameAliases  Confirmed by a human per organizer: userId => [raw legacy team/club name => canonical club name]. Same shape/purpose as GlobalCatalogBackfillService's.
     * @param  array<int, array<string, array{birth_year_from?: int|null, birth_year_to?: int|null}>>  $ageRanges  Same shape as GlobalCatalogBackfillService's -- applied to a category whether newly promoted or already promoted by a previous run.
     * @return array{categories: Collection, groups: Collection, clubs: Collection, teams: Collection}
     */
    public function run(bool $execute, array $clubNameAliases = [], array $ageRanges = []): array
    {
        $report = [
            'categories' => collect(),
            'groups' => collect(),
            'clubs' => collect(),
            'teams' => collect(),
        ];

        $build = function () use (&$report, $execute, $clubNameAliases, $ageRanges) {
            $this->promoteCategories($report, $execute, $ageRanges);
            $this->promoteGroups($report, $execute);
            $this->promoteClubsAndTeams($report, $execute, $clubNameAliases);
        };

        $execute ? DB::transaction($build) : $build();

        return $report;
    }

    /**
     * @param  array{categories: Collection}  $report
     * @param  array<int, array<string, array{birth_year_from?: int|null, birth_year_to?: int|null}>>  $ageRanges
     */
    private function promoteCategories(array &$report, bool $execute, array $ageRanges): void
    {
        $legacy = Category::query()->whereNotNull('tournament_id')->with('tournament')->orderBy('id')->get();

        // Seeded with names already claimed by a PRE-EXISTING global
        // category (from an earlier run, or a manually created one) so a
        // legacy category never silently reuses -- or gets merged into --
        // one it isn't actually the same row as. Compared in uppercase
        // because Category::name is uppercased on save (NormalizesToUppercase)
        // -- without this, "Teterito" and "TETERITO" look like different
        // names here but collide silently the moment both are saved.
        $claimedNames = [];
        foreach (Category::query()->whereNull('tournament_id')->get(['user_id', 'name']) as $existing) {
            $claimedNames[$existing->user_id.'|'.mb_strtoupper($existing->name)] = true;
        }

        foreach ($legacy as $category) {
            $tournament = $category->tournament;
            $userId = $tournament->user_id;
            $originalName = $category->name;
            $range = $ageRanges[$userId][$originalName] ?? null;

            $finalName = $originalName;
            $disambiguated = false;
            if (isset($claimedNames[$userId.'|'.mb_strtoupper($finalName)])) {
                $finalName = "{$originalName} ({$tournament->name})";
                $disambiguated = true;
            }
            $claimedNames[$userId.'|'.mb_strtoupper($finalName)] = true;

            if ($execute) {
                $category->user_id = $userId;
                $category->name = $finalName;
                $category->tournament_id = null;
                if ($range) {
                    $category->birth_year_from = $range['birth_year_from'] ?? $category->birth_year_from;
                    $category->birth_year_to = $range['birth_year_to'] ?? $category->birth_year_to;
                }
                $category->save();

                $tournament->globalCategories()->syncWithoutDetaching([$category->id]);
            }

            $report['categories']->push([
                'id' => $category->id,
                'user_id' => $userId,
                'original_name' => $originalName,
                'final_name' => $finalName,
                'disambiguated' => $disambiguated,
                'tournament' => $tournament->name,
                'birth_years' => $range ? "{$range['birth_year_from']}-{$range['birth_year_to']}" : null,
                'action' => $execute ? 'promovida' : 'se promovería',
            ]);
        }
    }

    /**
     * @param  array{groups: Collection}  $report
     */
    private function promoteGroups(array &$report, bool $execute): void
    {
        $legacy = Group::query()->whereNotNull('tournament_id')->with('category')->orderBy('id')->get();

        foreach ($legacy as $group) {
            if ($execute) {
                $group->tournament_id = null;
                $group->save();
            }

            $report['groups']->push([
                'id' => $group->id,
                'category' => $group->category->name,
                'name' => $group->name,
                'action' => $execute ? 'promovido' : 'se promovería',
            ]);
        }
    }

    /**
     * @param  array{clubs: Collection, teams: Collection}  $report
     * @param  array<int, array<string, string>>  $clubNameAliases
     */
    private function promoteClubsAndTeams(array &$report, bool $execute, array $clubNameAliases): void
    {
        $legacy = Team::query()->whereNotNull('tournament_id')->with(['tournament', 'category'])->orderBy('id')->get();

        /** @var array<string, int|string|null> $clubCache "{userId}|{displayName}" => Club id (real once persisted, or the key itself as a dry-run placeholder) */
        $clubCache = [];

        foreach ($legacy as $team) {
            $tournament = $team->tournament;
            $userId = $tournament->user_id;
            $displayName = $clubNameAliases[$userId][$team->name] ?? $team->name;
            $clubKey = $userId.'|'.$displayName;

            if (! array_key_exists($clubKey, $clubCache)) {
                $club = Club::query()->where('user_id', $userId)->where('name', $displayName)->first();
                $willCreate = ! $club;

                if ($willCreate && $execute) {
                    $club = new Club(['name' => $displayName]);
                    $club->user_id = $userId;
                    $club->save();
                }

                $clubCache[$clubKey] = $club?->id ?? ($execute ? null : $clubKey);

                $report['clubs']->push([
                    'user_id' => $userId,
                    'name' => $displayName,
                    'action' => $willCreate ? ($execute ? 'creado' : 'se crearía') : 'reutilizado',
                    'id' => $club?->id,
                ]);
            }

            $clubId = $clubCache[$clubKey];

            if ($execute && is_int($clubId)) {
                $team->club_id = $clubId;
                $team->tournament_id = null;
                $team->save();

                $tournament->globalTeams()->syncWithoutDetaching([$team->id]);
            }

            $report['teams']->push([
                'id' => $team->id,
                'club' => $displayName,
                'squad_name' => $team->name,
                'category' => $team->category->name,
                'tournament' => $tournament->name,
                'action' => $execute ? 'promovido' : 'se promovería',
            ]);
        }
    }
}
