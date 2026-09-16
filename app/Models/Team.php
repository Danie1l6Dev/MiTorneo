<?php

namespace App\Models;

use App\Models\Concerns\NormalizesToUppercase;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int|null $tournament_id Legacy owning tournament -- being phased
 *                                   out in favor of $club_id (a global club catalog) plus the
 *                                   tournament_team pivot. See docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md.
 * @property int|null $club_id The club this roster belongs to. Nullable
 *                             only until the backfill command populates it for rows created before
 *                             this column existed.
 * @property int $category_id
 * @property int|null $group_id
 * @property string $name
 * @property string|null $short_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Pivot|null $pivot Only
 *                present when this Team came off a BelongsToMany relation
 *                (Tournament::globalTeams(), Player::teams(), ...) -- null
 *                otherwise, e.g. a plain Team::find().
 */
#[Fillable(['name', 'short_name'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, NormalizesToUppercase;

    /** @var list<string> */
    protected array $uppercaseAttributes = ['name', 'short_name'];

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * See Category::ownerId() -- delegates to the category since a global
     * Team has no $tournament of its own to fall back on.
     */
    public function ownerId(): int
    {
        return $this->category->ownerId();
    }

    /**
     * Which of these team ids have at least one player with no
     * birth_date -- whether linked the "old" way (players.team_id) or via
     * player_team. Used to warn "can't fully validate this roster's
     * category eligibility yet" in the Clubes views without an N+1 query
     * per team. See docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md
     * (T01-11/T01-27).
     *
     * @param  iterable<int>  $teamIds
     * @return list<int>
     */
    public static function idsWithIncompletePlayers(iterable $teamIds): array
    {
        $teamIds = collect($teamIds)->values();
        if ($teamIds->isEmpty()) {
            return [];
        }

        $viaDirect = Player::query()
            ->whereIn('team_id', $teamIds)
            ->whereNull('birth_date')
            ->pluck('team_id');

        $viaPivot = DB::table('player_team')
            ->join('players', 'players.id', '=', 'player_team.player_id')
            ->whereIn('player_team.team_id', $teamIds)
            ->whereNull('players.birth_date')
            ->pluck('player_team.team_id');

        return $viaDirect->merge($viaPivot)->unique()->values()->all();
    }

    /**
     * Sorts an already-fetched batch of teams by their own category's age,
     * youngest first -- the same ordering Category::scopeOrderedByAge()
     * applies at the DB level, mirrored here in PHP for every place teams
     * are listed/checkboxed grouped by category (a club's own roster list,
     * the enrollment checkboxes on a player's create/edit form...), since a
     * JOIN-based DB scope would risk clobbering whatever ->with()/
     * ->withCount() the caller already chained. Each team's `category` must
     * already be eager-loaded -- this never queries on its own.
     *
     * @param  Collection<int, Team>  $teams
     * @return Collection<int, Team>
     */
    public static function sortedByCategoryAge(Collection $teams): Collection
    {
        return $teams->sortBy(fn (Team $team): array => [
            $team->category->birth_year_to === null ? 1 : 0,
            -($team->category->birth_year_to ?? 0),
            -($team->category->birth_year_from ?? 0),
            $team->category->name,
            $team->name,
        ])->values();
    }

    /**
     * The club this roster belongs to -- a club has one Team per
     * category(+group) it fields, this is that link.
     *
     * @return BelongsTo<Club, $this>
     */
    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    /**
     * Every tournament this roster is entered into -- via the
     * tournament_team pivot, not the legacy tournament_id column.
     *
     * @return BelongsToMany<Tournament, $this>
     */
    public function tournaments(): BelongsToMany
    {
        return $this->belongsToMany(Tournament::class, 'tournament_team');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return HasMany<TournamentMatch, $this>
     */
    public function homeMatches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class, 'home_team_id');
    }

    /**
     * @return HasMany<TournamentMatch, $this>
     */
    public function awayMatches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class, 'away_team_id');
    }

    /**
     * @deprecated Legacy relation via players.team_id -- being phased out
     *   in favor of $this->globalPlayers() (the player_team pivot). See
     *   docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md.
     *
     * @return HasMany<Player, $this>
     */
    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    /**
     * Every player actually rostered on this team -- via the player_team
     * pivot, which is what lets the same player appear on more than one
     * Team (e.g. two different categories for the same club).
     *
     * @return BelongsToMany<Player, $this>
     */
    public function globalPlayers(): BelongsToMany
    {
        return $this->belongsToMany(Player::class, 'player_team')->withPivot('jersey_number');
    }

    /**
     * Every player rostered on this team, across BOTH links: their
     * primary one (players.team_id, i.e. players()) and any secondary
     * one (the player_team pivot, i.e. globalPlayers()) -- see that
     * relation's own docblock for why a count of globalPlayers() alone
     * misses almost every player, since a player's first/primary team is
     * always the team_id link, never the pivot. Reads players_count/
     * global_players_count off ->withCount(['players', 'globalPlayers'])
     * when the caller already eager-loaded them, falling back to a fresh
     * query otherwise.
     */
    public function rosterPlayersCount(): int
    {
        return ($this->players_count ?? $this->players()->count())
            + ($this->global_players_count ?? $this->globalPlayers()->count());
    }

    /**
     * Every player who could be called up to play a match for this team:
     * this team's own roster (legacy team_id + player_team pivot) that
     * STILL fits this team's category's age rule, plus, for a global team,
     * every other roster of the SAME club whose player is age-eligible to
     * play UP into this team's category (Player::ageEligibleForCategory()
     * already allows a natural-or-older fit, never younger). An own-roster
     * player is only kept without a birth_date on file (missing data isn't
     * held against an existing enrollment, matching
     * ageEligibleForCategory()'s own "don't block" default) -- once they
     * DO have one and it no longer fits (e.g. after the category's allowed
     * years were edited), they drop out of this list until promoted to a
     * category that fits, see Player::promotionCandidateTeams(). A play-up
     * candidate from a sibling club roster is held to the stricter rule
     * either way: missing birth_date excludes them here, unlike everywhere
     * else that field is optional. This is deliberately broader than
     * globalPlayers()/players() alone -- see MatchLineup/MatchEventController
     * for what actually gates a match's quick-add roster to a subset of
     * this list. A team with no club (still a legacy per-tournament team)
     * only ever offers its own roster: there's no sibling club roster to
     * search across.
     *
     * @return Collection<int, Player>
     */
    public function clubPlayersEligibleForLineup(): Collection
    {
        if ($this->club_id === null) {
            return $this->players()->get()
                ->merge($this->globalPlayers()->get())
                ->unique('id')
                ->filter(fn (Player $player): bool => $player->ageEligibleForCategory($this->category))
                ->sortBy('full_name')
                ->values();
        }

        $clubTeamIds = static::query()->where('club_id', $this->club_id)->pluck('id');

        return Player::query()
            ->where(function ($query) use ($clubTeamIds) {
                $query->whereIn('team_id', $clubTeamIds)
                    ->orWhereHas('teams', fn ($q) => $q->whereIn('teams.id', $clubTeamIds));
            })
            ->with(['team.category', 'teams'])
            ->get()
            ->filter(fn (Player $player): bool => $player->ageEligibleForCategory($this->category)
                && ($player->team_id === $this->id
                    || $player->teams->contains('id', $this->id)
                    || $player->birth_date !== null))
            ->unique('id')
            ->sortBy('full_name')
            ->values();
    }

    /**
     * This team's own roster (legacy team_id + player_team pivot) filtered
     * down to whoever no longer fits its category's age rule -- typically
     * because the category's allowed years were edited after they were
     * already rostered. clubPlayersEligibleForLineup() silently drops these
     * from the convocatoria search panel instead of offering them for
     * call-up; this is what actually surfaces who's missing and why, see
     * the "Ya no pueden jugar en esta categoría" card on the match edit
     * page. Resolved via Player::promotionCandidateTeams()/promoteFromTeam().
     *
     * @return Collection<int, Player>
     */
    public function ineligibleRosterPlayers(): Collection
    {
        $roster = $this->players()->get();

        if (! $this->tournament_id) {
            $roster = $roster->merge($this->globalPlayers()->get())->unique('id');
        }

        return $roster
            ->filter(fn (Player $player): bool => ! $player->ageEligibleForCategory($this->category))
            ->sortBy('full_name')
            ->values();
    }

    /**
     * Every coach this team has ever had, active or not -- past ones are
     * deactivated rather than deleted so their tenure stays on record.
     *
     * @return HasMany<Coach, $this>
     */
    public function coaches(): HasMany
    {
        return $this->hasMany(Coach::class);
    }

    /**
     * The team's current head coach, if any -- null when it doesn't have one
     * registered yet. A team has at most one active coach at a time
     * (enforced in CoachController), so this is safe as a HasOne.
     *
     * @return HasOne<Coach, $this>
     */
    public function coach(): HasOne
    {
        return $this->hasOne(Coach::class)->where('is_active', true);
    }

    /**
     * Every match event recorded for this team's players, across every match.
     *
     * @return HasMany<MatchEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(MatchEvent::class);
    }

    /**
     * Every disciplinary Sanction recorded for this team's players/coaches,
     * across every match.
     *
     * @return HasMany<Sanction, $this>
     */
    public function sanctions(): HasMany
    {
        return $this->hasMany(Sanction::class);
    }

    /**
     * This team's own tournament_team row for $tournament -- where an
     * expulsion (see TeamExpulsionService) is recorded. Null when this team
     * was never linked to that tournament through the pivot at all (a
     * legacy per-tournament team that's never been expelled, most commonly).
     */
    private function tournamentPivotRow(Tournament $tournament): ?\stdClass
    {
        return DB::table('tournament_team')
            ->where('tournament_id', $tournament->id)
            ->where('team_id', $this->id)
            ->select('expelled_at', 'expulsion_reason')
            ->first();
    }

    /**
     * Whether this team was expelled from $tournament specifically -- an
     * expulsion never carries over to another tournament the same global
     * team later enters, nor to another Team row the same club fields in a
     * different (or the same) category. See TeamExpulsionService::expel().
     */
    public function isExpelledFrom(Tournament $tournament): bool
    {
        return $this->tournamentPivotRow($tournament)?->expelled_at !== null;
    }

    /**
     * The reason recorded for this team's expulsion from $tournament, if
     * any -- null both when it was never expelled and when no reason was
     * given.
     */
    public function expulsionReasonFor(Tournament $tournament): ?string
    {
        return $this->tournamentPivotRow($tournament)?->expulsion_reason;
    }

    /**
     * When this team was expelled from $tournament, if at all.
     */
    public function expelledAtFor(Tournament $tournament): ?Carbon
    {
        $expelledAt = $this->tournamentPivotRow($tournament)?->expelled_at;

        return $expelledAt ? Carbon::parse($expelledAt) : null;
    }
}
