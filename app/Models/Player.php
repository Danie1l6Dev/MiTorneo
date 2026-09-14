<?php

namespace App\Models;

use App\Enums\MatchEventType;
use App\Models\Concerns\NormalizesToUppercase;
use Database\Factories\PlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A roster entry for a team (a club's plantel in one category/group).
 * Match-level stats (goals, cards, ...) never belong as columns here
 * either -- those are rows in the match/player event table, not counters
 * on this model.
 *
 * @property int $id
 * @property int $team_id
 * @property string $full_name
 * @property string|null $document_number
 * @property int|null $jersey_number
 * @property Carbon|null $birth_date Nullable because
 *                                   players loaded before this field existed don't have it -- see the
 *                                   "dato incompleto" banner and the age-eligibility rule in
 *                                   docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md.
 *                                   Without it, this player cannot be added to any category beyond the one
 *                                   the backfill already inferred from their current team.
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['full_name', 'document_number', 'jersey_number', 'birth_date'])]
class Player extends Model
{
    /** @use HasFactory<PlayerFactory> */
    use HasFactory, NormalizesToUppercase;

    /** @var list<string> */
    protected array $uppercaseAttributes = ['full_name'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'birth_date' => 'date',
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
     * Finds this organizer's own existing player by document number --
     * whether reachable via the legacy $team_id (any team, global or
     * still per-tournament) or via a player_team link to a global team.
     * This is the "already registered?" lookup that lets adding a player
     * to a second plantel just link them instead of duplicating their
     * data -- see PlayerController::storeForTeam()/storeForClub() and
     * PlayerRequest::withValidator(), both built on this same query.
     */
    public static function findForOrganizer(string $documentNumber, int $ownerId): ?self
    {
        return static::query()
            ->where('document_number', $documentNumber)
            ->where(function ($query) use ($ownerId) {
                $query->whereHas('teams.club', fn ($q) => $q->where('user_id', $ownerId))
                    ->orWhereHas('team.club', fn ($q) => $q->where('user_id', $ownerId))
                    ->orWhereHas('team.tournament', fn ($q) => $q->where('user_id', $ownerId));
            })
            ->first();
    }

    /**
     * The club(s) this player is already part of, via any of their
     * current teams (legacy $team_id or player_team) that happen to be
     * global (have a club_id). Used to suggest "other planteles of the
     * same club" on their edit page -- see candidateTeamsForEnrollment().
     *
     * @return list<int>
     */
    public function clubIds(): array
    {
        $ids = collect([$this->team?->club_id]);

        $ids = $ids->merge($this->teams()->pluck('teams.club_id'));

        return $ids->filter()->unique()->values()->all();
    }

    /**
     * Planteles of this player's own club(s) they're NOT already on --
     * exactly what T01-27's "completa la fecha de nacimiento para
     * habilitar otras categorías" checkbox list on the player edit page
     * offers, once editing them is what actually supplies the missing
     * birth_date (the original backfill-era gap this closes).
     *
     * @return Collection<int, Team>
     */
    public function candidateTeamsForEnrollment(): Collection
    {
        $clubIds = $this->clubIds();
        if ($clubIds === []) {
            return collect();
        }

        $alreadyLinked = $this->teams()->pluck('teams.id')->push($this->team_id)->filter()->unique();

        return Team::query()
            ->whereIn('club_id', $clubIds)
            ->whereNotIn('id', $alreadyLinked)
            ->with(['category', 'group'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Every Team (club roster in one category/group) this player is
     * actually rostered on -- via the player_team pivot, not the legacy
     * $team_id column. A player can be linked to more than one Team (e.g.
     * the same kid on both "Nilmar Cebollita" and "Nilmar Infantil (A)"),
     * each with its own optional jersey_number. Which teams a player CAN
     * be linked to is gated by the age-eligibility rule (their natural
     * category by $birth_date, or any older one) enforced at assignment
     * time, not by this relation itself -- and gated entirely until
     * $birth_date is set.
     *
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'player_team')->withPivot('jersey_number');
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

    /**
     * Every disciplinary Sanction on record for this player, across every
     * match -- see Sanction's own docblock for how this differs from a
     * plain card event.
     *
     * @return HasMany<Sanction, $this>
     */
    public function sanctions(): HasMany
    {
        return $this->hasMany(Sanction::class);
    }

    /**
     * Every match this player was "convocado" (called up) for -- see
     * MatchLineup's docblock for how this differs from just belonging to a
     * team via $team_id/player_team.
     *
     * @return HasMany<MatchLineup, $this>
     */
    public function matchLineups(): HasMany
    {
        return $this->hasMany(MatchLineup::class);
    }

    /**
     * Age-eligibility rule (T01-11): a player can join a category that is
     * their natural one OR any OLDER category (higher physical level),
     * never a younger one. Categories are ranked by $birth_year_to -- a
     * LOWER value means an OLDER category (its kids were born earlier).
     * $playerBirthYear >= $category->birth_year_to therefore covers both
     * "fits exactly" and "playing up" in one comparison, and rejects
     * "playing down".
     *
     * Returns true (never blocks) when there isn't enough data to judge --
     * either this player has no birth_date, or the category has no
     * birth_year_to configured -- that absence is handled as its own,
     * separate policy at the call site (see PlayerRequest), not silently
     * enforced here.
     */
    public function ageEligibleForCategory(Category $category): bool
    {
        if ($this->birth_date === null || $category->birth_year_to === null) {
            return true;
        }

        return (int) $this->birth_date->format('Y') >= $category->birth_year_to;
    }

    /**
     * Whether this player currently owes fechas on ANY sanction -- a
     * red card is treated as suspending them from the moment it's recorded
     * (the same way being sent off keeps a player out under most
     * disciplinary codes even before a committee confirms/extends it), so
     * an unresolved (Pending) sanction counts too, not just a resolved one
     * still short of matches_banned. This is a general status check, not
     * "is this player blocked for THIS match" -- see
     * Sanction::blocksMatch() for that (used by
     * TournamentMatchController and the MatchEvent* requests).
     */
    public function isSuspended(): bool
    {
        return $this->sanctions()->get()->contains(fn (Sanction $sanction): bool => $sanction->stillOwesFechas());
    }
}
