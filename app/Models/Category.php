<?php

namespace App\Models;

use App\Enums\CategoryStatus;
use App\Models\Concerns\NormalizesToUppercase;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int|null $tournament_id Legacy owning tournament -- being phased
 *                                   out in favor of $user_id (a per-organizer global catalog) plus the
 *                                   tournament_category pivot. See docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md.
 * @property int|null $user_id The organizer this category's catalog belongs
 *                             to. Nullable only until the backfill command populates it for rows
 *                             created before this column existed.
 * @property string $name
 * @property string|null $description
 * @property CategoryStatus $status
 * @property bool $uses_groups
 * @property int|null $birth_year_from
 * @property int|null $birth_year_to
 * @property int $order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description', 'status', 'uses_groups', 'order', 'birth_year_from', 'birth_year_to'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, NormalizesToUppercase;

    /** @var list<string> */
    protected array $uppercaseAttributes = ['name'];

    protected function casts(): array
    {
        return [
            'status' => CategoryStatus::class,
            'uses_groups' => 'boolean',
        ];
    }

    /**
     * Youngest category first, oldest last -- everywhere categories are
     * listed in the app (global catalog, a tournament's own categories, the
     * clubs-by-category view, the enrollment checkboxes on a player's
     * form...) sorts this way instead of by the older, organizer-set
     * $order/$name. birth_year_to is what actually encodes age here: a
     * LARGER value means a YOUNGER category (its kids were born later) --
     * see Player::ageEligibleForCategory() for the same convention used to
     * decide play-up eligibility. A category with no age range configured
     * yet sorts last (its age is simply unknown, never assumed), then falls
     * back to $name for a stable, readable order among ties/unknowns.
     */
    public function scopeOrderedByAge(Builder $query): Builder
    {
        return $query
            ->orderByRaw('birth_year_to IS NULL')
            ->orderByDesc('birth_year_to')
            ->orderByDesc('birth_year_from')
            ->orderBy('name');
    }

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * The organizer that owns this category in their global catalog.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The organizer this category belongs to, regardless of whether it's
     * still a legacy per-tournament row ($tournament_id set, $user_id
     * null) or already a global one ($user_id set, $tournament_id null) --
     * see docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md.
     * Policies use this instead of reaching into $tournament->user_id
     * directly so they don't break once a category has no tournament.
     */
    public function ownerId(): int
    {
        return $this->tournament_id ? $this->tournament->user_id : $this->user_id;
    }

    /**
     * Every tournament (of this category's organizer) that includes this
     * category -- via the tournament_category pivot, not the legacy
     * tournament_id column.
     *
     * @return BelongsToMany<Tournament, $this>
     */
    public function tournaments(): BelongsToMany
    {
        return $this->belongsToMany(Tournament::class, 'tournament_category');
    }

    /**
     * @return HasMany<Group, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * @return HasMany<CompetitionPhase, $this>
     */
    public function competitionPhases(): HasMany
    {
        return $this->hasMany(CompetitionPhase::class);
    }

    /**
     * The roster to use for $tournament specifically -- NOT every team this
     * category has across its whole catalog. A still-legacy category
     * (pre-T02-01, `tournament_id` set directly) only ever had one
     * tournament, so its full `teams()` is already correct. A promoted
     * catalog category can have more teams than what THIS tournament
     * actually inscribed (an organizer can leave a team out of "Elegir
     * planteles", or add a new team to the catalog after that selection
     * was made) -- using the unscoped `teams()` there would leak
     * uninscribed teams into standings/schedules/brackets. See
     * docs/plan-reestructuracion/02-unificacion-categorias-torneo.md
     * (T02-11).
     *
     * @return Collection<int, Team>
     */
    public function teamsForTournament(Tournament $tournament): Collection
    {
        if ($this->tournament_id) {
            return $this->teams()->get();
        }

        return $tournament->globalTeams()->where('teams.category_id', $this->id)->get();
    }
}
