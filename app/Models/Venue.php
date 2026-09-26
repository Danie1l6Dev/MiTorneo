<?php

namespace App\Models;

use App\Models\Concerns\NormalizesToUppercase;
use Database\Factories\VenueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A cancha / lugar where matches are played. Global to the organizer (User),
 * not to a tournament -- the same way Referee is -- so the same cancha is
 * reused across every tournament they run. Being a real row (instead of the
 * old free-text matches.venue) is what lets scheduling detect two matches
 * booked on the same cancha at overlapping times.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class Venue extends Model
{
    /** @use HasFactory<VenueFactory> */
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
     * Every match scheduled on this cancha, across every tournament.
     *
     * @return HasMany<TournamentMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class);
    }
}
