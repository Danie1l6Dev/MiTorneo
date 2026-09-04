<?php

namespace App\Models;

use Database\Factories\PlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
}
