<?php

namespace App\Models;

use App\Models\Concerns\NormalizesToUppercase;
use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $tournament_id The tournament this group belongs to: every tournament has its own groups
 *                                   for a category. Null only for a group that could not be attributed.
 * @property int $category_id
 * @property string $name
 * @property int $order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'order'])]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory, NormalizesToUppercase;

    /** @var list<string> */
    protected array $uppercaseAttributes = ['name'];

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * See Category::ownerId() -- delegates to the parent category since
     * Group carries no owner of its own.
     */
    public function ownerId(): int
    {
        return $this->category->ownerId();
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The planteles in this group: the ones whose tournament_team row (in this
     * group's tournament) points at it. A plantel can be in "Grupo A" of one
     * tournament and "Grupo B" of another.
     *
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'tournament_team', 'group_id', 'team_id');
    }

    /**
     * @return HasMany<TournamentMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class);
    }

    /**
     * The tournament this group belongs to -- its own $tournament_id when
     * set (a still-legacy category, or a group created before its category
     * was promoted to the catalog), otherwise the sole tournament its
     * (global, catalog) category is currently enrolled in. See
     * Category::resolveSoleTournament().
     */
    public function resolvedTournament(): Tournament
    {
        return $this->tournament_id ? $this->tournament : $this->category->resolveSoleTournament();
    }
}
