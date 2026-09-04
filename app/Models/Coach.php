<?php

namespace App\Models;

use Database\Factories\CoachFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A team's head coach. Kept independent from Player on purpose -- a coach is
 * never a player, so this is never a "player with a flag" -- and to the same
 * minimal set of fields (phone, license, photo, etc. are not here yet, same
 * reasoning as Player). A team can accumulate several Coach rows over time
 * (past ones deactivated instead of deleted); at most one may be active per
 * team at once, enforced in CoachController rather than at the schema level.
 *
 * @property int $id
 * @property int $team_id
 * @property string $full_name
 * @property string $document_number
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['full_name', 'document_number'])]
class Coach extends Model
{
    /** @use HasFactory<CoachFactory> */
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
