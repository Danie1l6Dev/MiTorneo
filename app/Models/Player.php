<?php

namespace App\Models;

use App\Enums\MatchEventType;
use Database\Factories\PlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A minimal roster entry for a team. Deliberately kept to the fields the
 * tournament format has actually settled on -- birth date, position, photo,
 * etc. are intentionally not here yet; add them only once a real requirement
 * shows up, not speculatively. Match-level stats (goals, cards, ...) never
 * belong as columns here either -- those will be rows in a future
 * match/player event table, not counters on this model.
 *
 * @property int $id
 * @property int $team_id
 * @property string $full_name
 * @property string $document_number
 * @property int $jersey_number
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['full_name', 'document_number', 'jersey_number'])]
class Player extends Model
{
    /** @use HasFactory<PlayerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Every match event recorded for this player, across every match. Stats
     * (goals, assists, cards) are always counted from these rows on demand,
     * never cached as a column here.
     *
     * @return HasMany<MatchEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(MatchEvent::class);
    }

    /**
     * @return HasMany<MatchEvent, $this>
     */
    public function goals(): HasMany
    {
        return $this->events()->where('type', MatchEventType::Goal);
    }

    /**
     * @return HasMany<MatchEvent, $this>
     */
    public function assists(): HasMany
    {
        return $this->events()->where('type', MatchEventType::Assist);
    }

    /**
     * @return HasMany<MatchEvent, $this>
     */
    public function yellowCards(): HasMany
    {
        return $this->events()->where('type', MatchEventType::YellowCard);
    }

    /**
     * @return HasMany<MatchEvent, $this>
     */
    public function redCards(): HasMany
    {
        return $this->events()->where('type', MatchEventType::RedCard);
    }
}
