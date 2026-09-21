<?php

namespace App\Models;

use App\Enums\Gender;
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
use Illuminate\Support\Facades\DB;

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
 * @property Gender|null $gender Nullable for the same reason as $birth_date --
 *                               players loaded before this field existed
 *                               don't have it, see the "dato incompleto"
 *                               warning icon on the player row. Drives the
 *                               Category::$female_extra_birth_years
 *                               allowance in ageEligibleForCategory().
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['full_name', 'document_number', 'jersey_number', 'birth_date', 'gender'])]
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
            'gender' => Gender::class,
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
     * The club this player currently belongs to -- null for a still-legacy
     * per-tournament player (no club anywhere in their team_id/team_ids
     * chain) or one whose primary team has none. A player only ever
     * belongs to ONE club at a time: team_id and every player_team row are
     * always teams of that same club -- see blocksJoiningClub() for the
     * rule this enables.
     */
    public function currentClub(): ?Club
    {
        return $this->team?->club;
    }

    /**
     * Whether this player can't be added to $club right now because
     * they're still an ACTIVE part of a DIFFERENT club -- a player only
     * ever belongs to one club at a time (see currentClub()), so joining a
     * new one first requires deactivating them at their current one. That
     * deliberately keeps their old club's goals/cards/sanctions on record
     * instead of a move silently detaching them. Returns the blocking
     * club, or null when there's nothing stopping the move (already at
     * $club, not at any club yet, or inactive at their current one).
     */
    public function blocksJoiningClub(Club $club): ?Club
    {
        $current = $this->currentClub();

        if ($current === null || $current->id === $club->id || ! $this->is_active) {
            return null;
        }

        return $current;
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
     * Every Team this player is actually rostered on right now, combining
     * the legacy $team_id column with the player_team pivot into one
     * deduplicated list -- what the global player search (Clubes ›
     * Jugadores) shows per match, since a player can be on more than one
     * plantel. $team and $teams must already be eager-loaded by the caller
     * (see searchForOrganizer()) to avoid an N+1 per result.
     *
     * @return Collection<int, Team>
     */
    public function allTeams(): Collection
    {
        return collect([$this->team])
            ->merge($this->teams)
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * Every player of this organizer's own catalog, across every club/
     * category -- not scoped to one team. This is what the Clubes
     * "Jugadores" view preloads for its live, client-side name/document
     * search (a live search endpoint would be overkill at this catalog's
     * actual size). Built on the same ownership check findForOrganizer()
     * already uses (team_id or the player_team pivot).
     *
     * @return Collection<int, Player>
     */
    public static function allForOrganizer(int $ownerId): Collection
    {
        return static::query()
            ->where(function ($ownerQuery) use ($ownerId) {
                $ownerQuery->whereHas('teams.club', fn ($q) => $q->where('user_id', $ownerId))
                    ->orWhereHas('team.club', fn ($q) => $q->where('user_id', $ownerId))
                    ->orWhereHas('team.tournament', fn ($q) => $q->where('user_id', $ownerId));
            })
            ->with(['team.category', 'team.club', 'teams.category', 'teams.club'])
            ->orderBy('full_name')
            ->get();
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

        $teams = Team::query()
            ->whereIn('club_id', $clubIds)
            ->whereNotIn('id', $alreadyLinked)
            ->with(['category', 'group'])
            ->orderBy('name')
            ->get();

        return Team::sortedByCategoryAge($teams);
    }

    /**
     * The subset of candidateTeamsForEnrollment() that actually fits this
     * player once $fromTeam's category no longer does: same club as
     * $fromTeam, age-eligible, sorted youngest-first so the CLOSEST allowed
     * category -- the one right above $fromTeam's -- comes first.
     * ageEligibleForCategory() has no upper limit on how much OLDER a
     * category can be (playing up is always allowed), so this list can
     * legitimately hold several older categories at once; ->first() is what
     * bulk promotion (PlayerController::promoteEligible()) uses to always
     * move someone to the nearest one deterministically instead of stalling
     * on "which of these", while the single-player picker shows the whole
     * list so a human can deliberately place someone further up. Empty when
     * $fromTeam has no club (a legacy per-tournament team has no sibling
     * roster to promote into) or when nothing in this club's catalog fits
     * them yet (they'd need an even older category the club hasn't fielded
     * a team for).
     *
     * @return Collection<int, Team>
     */
    public function promotionCandidateTeams(Team $fromTeam): Collection
    {
        if ($fromTeam->club_id === null) {
            return collect();
        }

        return $this->candidateTeamsForEnrollment()
            ->where('club_id', $fromTeam->club_id)
            ->filter(fn (Team $team): bool => $this->ageEligibleForCategory($team->category))
            ->values();
    }

    /**
     * Moves this player from $from to $to -- replacing the link, not adding
     * a second one alongside it. Whichever way they were actually linked to
     * $from (the legacy team_id column, or a player_team row) is what gets
     * replaced; a player_team jersey_number carries over to the new row.
     * team_id is set directly (not via update()) since it's deliberately
     * left out of #[Fillable] -- mass assignment would silently no-op here,
     * the same reason storeForTeam()/storeForClub() set it this way too.
     */
    public function promoteFromTeam(Team $from, Team $to): void
    {
        if ($this->team_id === $from->id) {
            $this->team_id = $to->id;
            $this->save();

            return;
        }

        $jerseyNumber = DB::table('player_team')
            ->where('player_id', $this->id)
            ->where('team_id', $from->id)
            ->value('jersey_number');

        $this->teams()->detach($from->id);
        $this->teams()->attach($to->id, ['jersey_number' => $jerseyNumber]);
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
     * Age-eligibility rule (T01-11): a player can join a category that is
     * their natural one OR any OLDER category (higher physical level),
     * never a younger one. Categories are ranked by $birth_year_to -- a
     * LOWER value means an OLDER category (its kids were born earlier).
     * (the cutoff is birth_year_from -- the category's oldest birth year --
     * when set, else birth_year_to.)
     * $playerBirthYear >= that cutoff therefore covers both
     * "fits exactly" and "playing up" in one comparison, and rejects
     * "playing down".
     *
     * Returns true (never blocks) when there isn't enough data to judge --
     * either this player has no birth_date, or the category has no
     * birth_year_to configured -- that absence is handled as its own,
     * separate policy at the call site (see PlayerRequest), not silently
     * enforced here.
     *
     * A mixed category can let girls play with boys while being a few years
     * older than the boys' own cutoff (Category::$female_extra_birth_years)
     * -- a LOWER threshold, since a lower birth_year_to admits earlier
     * (older) birth years, same convention as everywhere else here. Only
     * ever relaxes the check: a player with no $gender on file (or a male
     * one) is judged by the category's plain $birth_year_to, never
     * penalized for the missing data.
     */
    public function ageEligibleForCategory(Category $category): bool
    {
        if ($this->birth_date === null || $category->birth_year_to === null) {
            return true;
        }

        // The cutoff is the category's OLDEST birth year (birth_year_from),
        // so a kid born in the first year of a 2-year range (PRE-PONY
        // 2015-2016, born 2015) fits their own category. Categories that
        // only have birth_year_to configured fall back to it.
        $threshold = $category->birth_year_from ?? $category->birth_year_to;

        if ($this->gender === Gender::Female && $category->female_extra_birth_years) {
            $threshold -= $category->female_extra_birth_years;
        }

        return (int) $this->birth_date->format('Y') >= $threshold;
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
