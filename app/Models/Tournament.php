<?php

namespace App\Models;

use App\Enums\TournamentStatus;
use App\Models\Concerns\NormalizesToUppercase;
use Database\Factories\TournamentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
    use HasFactory, NormalizesToUppercase;

    /** @var list<string> */
    protected array $uppercaseAttributes = ['name'];

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
     * @deprecated Legacy relation via categories.tournament_id -- being
     *   phased out in favor of $this->globalCategories() (the
     *   tournament_category pivot). See
     *   docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md.
     *
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class)->orderedByAge();
    }

    /**
     * Which categories (from the organizer's global catalog) this
     * tournament includes -- via the tournament_category pivot. Ordered
     * youngest-to-oldest (Category::scopeOrderedByAge()), same as
     * categories() above, so this tournament's own category listing
     * (its show page, the public portal, ...) reads consistently with the
     * rest of the app.
     *
     * @return BelongsToMany<Category, $this>
     */
    public function globalCategories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'tournament_category')->orderedByAge();
    }

    /**
     * Which teams (club rosters) are entered into this tournament -- via
     * the tournament_team pivot. This is the full roster per
     * category/group; competition_phase_team narrows that further to a
     * single phase (e.g. "top 2 of each group").
     *
     * @return BelongsToMany<Team, $this>
     */
    public function globalTeams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'tournament_team');
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
