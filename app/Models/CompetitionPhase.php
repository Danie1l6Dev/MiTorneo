<?php

namespace App\Models;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchStatus;
use Database\Factories\CompetitionPhaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tournament_id
 * @property int $category_id
 * @property string $name
 * @property CompetitionPhaseType $type
 * @property int $order
 * @property int|null $champion_team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'type', 'order'])]
class CompetitionPhase extends Model
{
    /** @use HasFactory<CompetitionPhaseFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => CompetitionPhaseType::class,
        ];
    }

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<TournamentMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class);
    }

    /**
     * @return HasMany<LeagueSchedule, $this>
     */
    public function leagueSchedules(): HasMany
    {
        return $this->hasMany(LeagueSchedule::class);
    }

    /**
     * The teams that specifically qualified into this phase (e.g. via a draw or
     * a "top N per table" cutoff), independent of category-wide groups. Empty
     * for a phase that simply plays with the whole category/group as-is, such
     * as a category's first league phase.
     *
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'competition_phase_team')->withTimestamps();
    }

    /**
     * The team directly declared champion from this phase's standings,
     * instead of the phase spawning a further phase to decide one -- only
     * ever set on a single-table league phase; a knockout-type phase's
     * champion is derived from its bracket's final match instead.
     *
     * @return BelongsTo<Team, $this>
     */
    public function champion(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'champion_team_id');
    }

    /**
     * Whether this phase has at least one match and every one of them is finished.
     */
    public function allMatchesFinished(): bool
    {
        return $this->matches->isNotEmpty()
            && $this->matches->every(fn (TournamentMatch $match): bool => $match->status === MatchStatus::Finished);
    }
}
