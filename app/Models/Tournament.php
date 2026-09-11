<?php

namespace App\Models;

use App\Enums\TournamentStatus;
use Database\Factories\TournamentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $slug
 * @property string|null $description
 * @property string|null $season
 * @property TournamentStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description', 'season', 'status'])]
class Tournament extends Model
{
    /** @use HasFactory<TournamentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TournamentStatus::class,
        ];
    }

    /**
     * A URL-friendly, globally unique identifier used only by the public
     * portal (see routes/public.php's `{tournament:slug}` binding) -- never
     * mass-assignable, and only ever set once at creation time
     * (TournamentController::store()) so a link the organizer already
     * shared keeps working even after the tournament is renamed. The only
     * other time it changes is an explicit, manual regenerateSlug() call
     * (e.g. from the admin "Regenerar enlace" button) -- a deliberate escape
     * hatch for a broken/leaked link, not something that happens on its own.
     */
    public static function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'torneo';
        $slug = $base;
        $suffix = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * Replace this tournament's slug with a fresh, different one and persist
     * it immediately -- the old public link stops resolving right away. A
     * random suffix (not just re-deriving from the name) guarantees the new
     * value actually differs from the current one: re-running
     * generateUniqueSlug($this->name) here could otherwise hand back this
     * same slug once it's no longer "taken" by excluding this tournament's
     * own row, which would defeat the point of a "regenerate" action.
     */
    public function regenerateSlug(): string
    {
        $base = Str::slug($this->name) ?: 'torneo';

        do {
            $candidate = $base.'-'.Str::lower(Str::random(5));
        } while (static::query()->where('slug', $candidate)->where('id', '!=', $this->id)->exists());

        $this->slug = $candidate;
        $this->save();

        return $candidate;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * @return HasMany<CompetitionPhase, $this>
     */
    public function competitionPhases(): HasMany
    {
        return $this->hasMany(CompetitionPhase::class);
    }

    /**
     * @return HasMany<Group, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * @return HasMany<TournamentMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class);
    }
}
