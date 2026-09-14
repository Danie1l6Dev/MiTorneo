<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Club;
use App\Models\Group;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the global (per-organizer) catalog -- Category, Group, Club,
 * Team, and player_team links -- from the legacy per-tournament data. See
 * docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md
 * (T01-15/T01-16) for the plan this implements.
 *
 * Never mutates or deletes a legacy row or column -- it only ever creates
 * NEW rows (or reuses ones a previous run already created), so it's safe
 * to run repeatedly (idempotent) and safe to run before the app has cut
 * over to reading the new schema (nothing legacy changes underneath it).
 *
 * Identity rule (plan decisions #2/#3): same exact name + same organizer
 * (tournament.user_id) = same real-world entity, merged automatically.
 * Plan decision #6: the same club+category+group appearing in more than
 * one tournament merges into ONE global Team, with every legacy tournament
 * linked to it via tournament_team. No fuzzy matching beyond exact name --
 * "Nilmar (A)" and "Nilmar (B)" are never auto-merged into one club, that
 * was never confirmed; see collectNameFamilyHints() for the informational
 * (non-mutating) hint shown for those instead.
 */
class GlobalCatalogBackfillService
{
    /**
     * @param  array<int, array<string, string>>  $clubNameAliases  Confirmed by a human (never guessed) per organizer: userId => [raw legacy club name => canonical club name]. Applied ONLY to resolve/create the Club -- the Team itself always keeps matching on the raw legacy name (see backfillClubsAndTeams), so aliasing "NILMAR (A)"/"NILMAR (B)" onto one Club "NILMAR" still leaves them as two separate squads under it, never silently merging their rosters.
     * @param  array<int, array<string, array{birth_year_from?: int|null, birth_year_to?: int|null}>>  $ageRanges  Confirmed by a human (e.g. an official age-eligibility table, never guessed) per organizer: userId => [category name => range]. Nothing in the legacy schema has this data, so it can't be inferred from anything -- see docs/plan-reestructuracion/faudis-rango-edades-2026.json for a real example. Applied to a category whether it's newly created or already existed, so re-running with an updated ranges file keeps them current.
     * @return array{
     *     categories: Collection<int, array<string, mixed>>,
     *     groups: Collection<int, array<string, mixed>>,
     *     clubs: Collection<int, array<string, mixed>>,
     *     teams: Collection<int, array<string, mixed>>,
     *     player_links: Collection<int, array<string, mixed>>,
     *     name_family_hints: Collection<int, array<string, mixed>>,
     * }
     */
    public function run(bool $execute, array $clubNameAliases = [], array $ageRanges = []): array
    {
        $report = [
            'categories' => collect(),
            'groups' => collect(),
            'clubs' => collect(),
            'teams' => collect(),
            'player_links' => collect(),
            'name_family_hints' => collect(),
        ];

        $build = function () use (&$report, $execute, $clubNameAliases, $ageRanges) {
            $categoryIds = $this->backfillCategories($report, $execute, $ageRanges);
            $groupIds = $this->backfillGroups($report, $execute, $categoryIds);
            $teamIds = $this->backfillClubsAndTeams($report, $execute, $categoryIds, $groupIds, $clubNameAliases);
            $this->backfillPlayerLinks($report, $execute, $teamIds);
        };

        // Dry-run never writes, so it doesn't need transactional safety --
        // running it inside one anyway would be harmless but pointless.
        $execute ? DB::transaction($build) : $build();

        $this->collectNameFamilyHints($report);

        return $report;
    }

    /**
     * @param  array{categories: Collection}  $report
     * @param  array<int, array<string, array{birth_year_from?: int|null, birth_year_to?: int|null}>>  $ageRanges
     * @return array<string, int|string|null> "{userId}|{name}" => canonical Category id (real id once persisted, or a placeholder key during --dry-run)
     */
    private function backfillCategories(array &$report, bool $execute, array $ageRanges = []): array
    {
        $legacy = Category::query()->whereNotNull('tournament_id')->with('tournament')->get();

        $map = [];

        foreach ($legacy->groupBy(fn (Category $category) => $category->tournament->user_id.'|'.$category->name) as $key => $rows) {
            $template = $rows->first();
            $userId = $template->tournament->user_id;
            $name = $template->name;
            $range = $ageRanges[$userId][$name] ?? null;

            $canonical = Category::query()
                ->whereNull('tournament_id')
                ->where('user_id', $userId)
                ->where('name', $name)
                ->first();
            $willCreate = ! $canonical;

            if ($willCreate && $execute) {
                $canonical = new Category([
                    'name' => $name,
                    'description' => $template->description,
                    'status' => $template->status,
                    'uses_groups' => $template->uses_groups,
                    'order' => $template->order,
                    'birth_year_from' => $range['birth_year_from'] ?? null,
                    'birth_year_to' => $range['birth_year_to'] ?? null,
                ]);
                $canonical->user_id = $userId;
                $canonical->save();
            } elseif ($execute && $canonical && $range) {
                // Reused (already existed, maybe from a previous run
                // before an ages file was available) -- still apply a
                // provided range so re-running stays self-healing instead
                // of only ever setting it at creation time.
                $canonical->fill([
                    'birth_year_from' => $range['birth_year_from'] ?? $canonical->birth_year_from,
                    'birth_year_to' => $range['birth_year_to'] ?? $canonical->birth_year_to,
                ])->save();
            }

            if ($execute && $canonical) {
                foreach ($rows as $legacyCategory) {
                    $legacyCategory->tournament->globalCategories()->syncWithoutDetaching([$canonical->id]);
                }
            }

            $map[$key] = $this->resolveOrPlaceholder($canonical, $key, $execute);

            $report['categories']->push([
                'user_id' => $userId,
                'name' => $name,
                'action' => $this->describeAction($willCreate, $execute),
                'canonical_id' => $canonical?->id,
                'birth_years' => $range ? "{$range['birth_year_from']}-{$range['birth_year_to']}" : null,
                'tournaments' => $rows->pluck('tournament.name')->all(),
                'legacy_category_ids' => $rows->pluck('id')->all(),
            ]);
        }

        return $map;
    }

    /**
     * @param  array<string, int|null>  $categoryIds
     * @return array<string, int|string|null> "{userId}|{categoryName}|{groupName}" => canonical Group id (real id once persisted, or a placeholder key during --dry-run)
     */
    private function backfillGroups(array &$report, bool $execute, array $categoryIds): array
    {
        $legacy = Group::query()->whereNotNull('tournament_id')->with(['category.tournament'])->get();

        $map = [];

        foreach ($legacy->groupBy(fn (Group $group) => $group->category->tournament->user_id.'|'.$group->category->name.'|'.$group->name) as $key => $rows) {
            $template = $rows->first();
            $userId = $template->category->tournament->user_id;
            $categoryKey = $userId.'|'.$template->category->name;
            $canonicalCategoryId = $categoryIds[$categoryKey] ?? null;

            $canonical = is_int($canonicalCategoryId)
                ? Group::query()->whereNull('tournament_id')->where('category_id', $canonicalCategoryId)->where('name', $template->name)->first()
                : null;
            $willCreate = ! $canonical;

            if ($willCreate && $execute && $canonicalCategoryId) {
                $canonical = new Group([
                    'name' => $template->name,
                    'order' => $template->order,
                ]);
                $canonical->category_id = $canonicalCategoryId;
                $canonical->tournament_id = null;
                $canonical->save();
            }

            $map[$key] = $this->resolveOrPlaceholder($canonical, $key, $execute);

            $report['groups']->push([
                'user_id' => $userId,
                'category' => $template->category->name,
                'name' => $template->name,
                'action' => $this->describeAction($willCreate, $execute && (bool) $canonicalCategoryId),
                'canonical_id' => $canonical?->id,
                'legacy_group_ids' => $rows->pluck('id')->all(),
                'blocked_missing_category' => ! $canonicalCategoryId,
            ]);
        }

        return $map;
    }

    /**
     * @param  array<string, int|null>  $categoryIds
     * @param  array<string, int|null>  $groupIds
     * @param  array<int, array<string, string>>  $clubNameAliases  userId => [raw team/club name => canonical club name], confirmed by a human -- see run()'s docblock.
     * @return array{ids: array<int, int|null>, keys: array<int, string|null>} legacy Team id => [real canonical Team id, stable group key]
     */
    private function backfillClubsAndTeams(array &$report, bool $execute, array $categoryIds, array $groupIds, array $clubNameAliases = []): array
    {
        $legacy = Team::query()->whereNotNull('tournament_id')->with(['tournament', 'category', 'group'])->get();

        // Two maps on purpose: $teamIdMap only ever holds a REAL Team id
        // (only meaningful once $execute has actually created/reused one),
        // while $teamKeyMap holds a stable per-group key even during
        // --dry-run (when nothing has an id yet) -- backfillPlayerLinks
        // uses the key map to report "this player resolves to a team"
        // without pretending a real id exists before one does.
        $teamIdMap = [];
        $teamKeyMap = [];
        $clubIdCache = [];

        $grouped = $legacy->groupBy(function (Team $team) {
            $userId = $team->tournament->user_id;
            $groupName = $team->group?->name ?? '';

            // The RAW team name is part of the identity on purpose -- see
            // the note on $clubNameAliases below. Two squads of the same
            // club in the same category(+group), e.g. "NILMAR (A)" /
            // "NILMAR (B)" fielded because one roster doesn't fit every
            // kid, must never collapse into a single Team just because
            // they share a club.
            return $userId.'|'.$team->name.'|'.$team->category->name.'|'.$groupName;
        });

        foreach ($grouped as $key => $rows) {
            $template = $rows->first();
            $userId = $template->tournament->user_id;
            // The alias only ever changes which CLUB a raw name resolves
            // to -- it never touches the Team's own identity below, which
            // always keys on $template->name as originally entered. That's
            // what lets "NILMAR (A)" and "NILMAR (B)" both fold into one
            // Club "NILMAR" while staying two separate squads under it.
            $clubDisplayName = $clubNameAliases[$userId][$template->name] ?? $template->name;
            $clubKey = $userId.'|'.$clubDisplayName;

            if (! array_key_exists($clubKey, $clubIdCache)) {
                $club = Club::query()->where('user_id', $userId)->where('name', $clubDisplayName)->first();
                $clubWillCreate = ! $club;

                if ($clubWillCreate && $execute) {
                    $club = new Club(['name' => $clubDisplayName]);
                    $club->user_id = $userId;
                    $club->save();
                }

                $clubIdCache[$clubKey] = $this->resolveOrPlaceholder($club, $clubKey, $execute);

                $report['clubs']->push([
                    'user_id' => $userId,
                    'name' => $clubDisplayName,
                    'action' => $this->describeAction($clubWillCreate, $execute),
                    'canonical_id' => $club?->id,
                ]);
            }

            $clubId = $clubIdCache[$clubKey];
            $canonicalCategoryId = $categoryIds[$userId.'|'.$template->category->name] ?? null;
            $canonicalGroupId = $template->group
                ? ($groupIds[$userId.'|'.$template->category->name.'|'.$template->group->name] ?? null)
                : null;

            // Only query with real ids -- a placeholder string (dry-run,
            // parent not created yet) can't match any existing row anyway,
            // and comparing an int column to an arbitrary string is asking
            // for trouble across DB drivers.
            $idsAreReal = is_int($clubId) && is_int($canonicalCategoryId) && ($canonicalGroupId === null || is_int($canonicalGroupId));

            // $template->name (never the aliased club display name) is
            // part of this lookup on purpose -- see the note above on
            // $clubNameAliases. Without it, two squads of the SAME club
            // fielded in the SAME category(+group) -- e.g. "NILMAR (A)"
            // and "NILMAR (B)" in a category with no real sub-groups,
            // fielded only because one roster doesn't fit every kid --
            // would silently collapse into a single Team the moment both
            // names alias to the same Club, merging their rosters.
            $canonicalTeam = $idsAreReal
                ? Team::query()
                    ->whereNull('tournament_id')
                    ->where('club_id', $clubId)
                    ->where('category_id', $canonicalCategoryId)
                    ->where('group_id', $canonicalGroupId)
                    ->where('name', $template->name)
                    ->first()
                : null;
            $willCreateTeam = ! $canonicalTeam;

            if ($willCreateTeam && $execute && $clubId && $canonicalCategoryId) {
                $canonicalTeam = new Team([
                    'name' => $template->name,
                    'short_name' => $template->short_name,
                ]);
                $canonicalTeam->club_id = $clubId;
                $canonicalTeam->category_id = $canonicalCategoryId;
                $canonicalTeam->group_id = $canonicalGroupId;
                $canonicalTeam->tournament_id = null;
                $canonicalTeam->save();
            }

            if ($execute && $canonicalTeam) {
                foreach ($rows as $legacyTeam) {
                    $legacyTeam->tournament->globalTeams()->syncWithoutDetaching([$canonicalTeam->id]);
                }
            }

            $resolvedKey = ($clubId && $canonicalCategoryId) ? $key : null;
            foreach ($rows as $legacyTeam) {
                $teamIdMap[$legacyTeam->id] = $canonicalTeam?->id;
                $teamKeyMap[$legacyTeam->id] = $resolvedKey;
            }

            $report['teams']->push([
                'club' => $clubDisplayName,
                'squad_name' => $template->name,
                'category' => $template->category->name,
                'group' => $template->group?->name,
                'action' => $this->describeAction($willCreateTeam, $execute && (bool) ($clubId && $canonicalCategoryId)),
                'canonical_id' => $canonicalTeam?->id,
                'is_merge' => $rows->count() > 1,
                'merged_from_tournaments' => $rows->pluck('tournament.name')->all(),
                'legacy_team_ids' => $rows->pluck('id')->all(),
                'blocked' => ! $resolvedKey,
            ]);
        }

        return ['ids' => $teamIdMap, 'keys' => $teamKeyMap];
    }

    /**
     * @param  array{ids: array<int, int|null>, keys: array<int, string|null>}  $teamMaps  legacy Team id => [real DB id (only once created), stable group key (even pre-creation)]
     */
    private function backfillPlayerLinks(array &$report, bool $execute, array $teamMaps): void
    {
        $players = Player::query()->whereNotNull('team_id')->with('team')->get();

        foreach ($players as $player) {
            // A player created (or re-linked) after the app cut over to
            // the new schema has team_id pointing straight at a global
            // Team already -- e.g. via the "Jugadores" flow in
            // PlayerController::storeForTeam(), which never needed
            // player_team for a first/only team. There's nothing legacy
            // to translate here, so this must never show up as
            // "unresolved" on a later re-run of this command.
            if ($player->team && $player->team->tournament_id === null) {
                $report['player_links']->push([
                    'player' => $player->full_name,
                    'player_id' => $player->id,
                    'legacy_team_id' => $player->team_id,
                    'canonical_team_id' => $player->team_id,
                    'action' => 'ya vinculado',
                ]);

                continue;
            }

            $canonicalTeamId = $teamMaps['ids'][$player->team_id] ?? null;
            $resolvedKey = $teamMaps['keys'][$player->team_id] ?? null;

            $alreadyLinked = $canonicalTeamId
                ? DB::table('player_team')->where('player_id', $player->id)->where('team_id', $canonicalTeamId)->exists()
                : false;

            if ($execute && $canonicalTeamId && ! $alreadyLinked) {
                DB::table('player_team')->insert([
                    'player_id' => $player->id,
                    'team_id' => $canonicalTeamId,
                    'jersey_number' => $player->jersey_number,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $report['player_links']->push([
                'player' => $player->full_name,
                'player_id' => $player->id,
                'legacy_team_id' => $player->team_id,
                'canonical_team_id' => $canonicalTeamId,
                'action' => match (true) {
                    $resolvedKey === null => 'unresolved (plantel bloqueado -- revisar categoría/club de origen)',
                    $alreadyLinked => 'ya vinculado',
                    $execute => 'vinculado',
                    default => 'se vincularía',
                },
            ]);
        }
    }

    /**
     * Purely informational: clubs whose name shares a base (everything
     * before a trailing " (X)" or " - X") with another club of the same
     * organizer, e.g. "Nilmar (A)" / "Nilmar (B)". This is NEVER merged
     * automatically -- only exact-name matches are (see backfillClubsAndTeams)
     * -- it's surfaced so a human can decide whether these are really the
     * same club fielding multiple squads under inconsistent naming.
     */
    private function collectNameFamilyHints(array &$report): void
    {
        $byUser = $report['clubs']->groupBy('user_id');

        foreach ($byUser as $userId => $clubs) {
            $names = $clubs->pluck('name')->unique()->values();

            $families = $names
                ->groupBy(fn (string $name) => trim((string) preg_replace('/\s*[\(\-].*$/', '', $name)))
                ->filter(fn (Collection $group, string $base) => $group->count() > 1 && $base !== '');

            foreach ($families as $base => $group) {
                $report['name_family_hints']->push([
                    'user_id' => $userId,
                    'possible_base_name' => $base,
                    'club_names' => $group->values()->all(),
                ]);
            }
        }
    }

    private function describeAction(bool $willCreate, bool $execute): string
    {
        return match (true) {
            ! $willCreate => 'reutilizado',
            $execute => 'creado',
            default => 'se crearía',
        };
    }

    /**
     * The id downstream steps should reference for this (possibly not yet
     * persisted) row: the real DB id once one exists (reused or just
     * created), or -- only during --dry-run, before anything is written --
     * a stable placeholder (the row's own grouping key) so a later step can
     * still tell "this resolves to something" apart from "this is
     * genuinely blocked", without pretending a real id exists yet. Never
     * returned during $execute=true with no real id, since every non-blocked
     * row is actually created in that mode.
     */
    private function resolveOrPlaceholder(?Model $canonical, string $key, bool $execute): int|string|null
    {
        return $canonical?->id ?? ($execute ? null : $key);
    }
}
