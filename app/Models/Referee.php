<?php

namespace App\Models;

use Database\Factories\RefereeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A referee is a global, reusable entity -- it does not belong to any one
 * tournament. It's owned directly by the organizer (User) who registered
 * it, the same way Tournament is, which is what lets the same referee be
 * assigned to matches across different tournaments. Kept to the same
 * minimal field set as Coach/Player (full name + document) until a real
 * need for more comes up.
 *
 * @property int $id
 * @property int $user_id
 * @property string $full_name
 * @property string $document_number
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['full_name', 'document_number'])]
class Referee extends Model
{
    /** @use HasFactory<RefereeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Every match this referee has been assigned to, across every
     * tournament -- the source of truth for how many matches they've
     * directed and which ones, never a stored counter.
     *
     * @return HasMany<TournamentMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class);
    }
}
