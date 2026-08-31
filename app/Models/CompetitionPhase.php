<?php

namespace App\Models;

use App\Enums\CompetitionPhaseType;
use Database\Factories\CompetitionPhaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tournament_id
 * @property int $category_id
 * @property string $name
 * @property CompetitionPhaseType $type
 * @property int $order
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
}
