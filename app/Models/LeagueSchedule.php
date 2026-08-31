<?php

namespace App\Models;

use App\Enums\ScheduleFormat;
use Carbon\CarbonImmutable;
use Database\Factories\LeagueScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tournament_id
 * @property int $competition_phase_id
 * @property int|null $group_id
 * @property ScheduleFormat $format
 * @property CarbonImmutable $generated_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['format'])]
class LeagueSchedule extends Model
{
    /** @use HasFactory<LeagueScheduleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'format' => ScheduleFormat::class,
            'generated_at' => 'datetime',
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
     * @return BelongsTo<CompetitionPhase, $this>
     */
    public function competitionPhase(): BelongsTo
    {
        return $this->belongsTo(CompetitionPhase::class);
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
    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class);
    }
}
