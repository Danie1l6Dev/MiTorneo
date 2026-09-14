<?php

namespace App\Models;

use App\Models\Concerns\NormalizesToUppercase;
use Database\Factories\ClubFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A global (per-organizer) club, e.g. "Nilmar". A club itself doesn't play
 * anything -- it fields one or more Team rows, each a squad in one
 * category (+group) it participates in (see Team::club()), with its own
 * players/coaches. A club can field more than one squad in the very same
 * category/group too (e.g. "Nilmar (A)" and "Nilmar (B)" in a category
 * that doesn't even use groups, simply because one roster doesn't fit
 * every kid) -- Team rows are told apart by name in that case, not by
 * category/group alone.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class Club extends Model
{
    /** @use HasFactory<ClubFactory> */
    use HasFactory, NormalizesToUppercase;

    /** @var list<string> */
    protected array $uppercaseAttributes = ['name'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function ownerId(): int
    {
        return $this->user_id;
    }
}
