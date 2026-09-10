<?php

namespace App\Models;

use App\Enums\MatchStatus;
use App\Enums\SanctionStatus;
use App\Enums\SanctionType;
use Database\Factories\SanctionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A disciplinary consequence for a player or a coach, kept deliberately
 * separate from MatchEvent: a card is something that happened in a match
 * (an umpire's/referee's on-field call, recorded once and never changed); a
 * Sanction is the administrative decision that follows it, which can be
 * fixed automatically (rule 1: two yellow cards in the same match always
 * cost exactly one fecha) or stay open until the Comité Directivo resolves
 * it (rule 2: a straight red never assumes its own duration). One
 * MatchEvent card can exist with no Sanction at all (a lone caution), and a
 * Sanction's own duration/fine live only here, never inferred again from
 * the card once resolved.
 *
 * SanctionService is the only place that creates, updates or deletes these
 * rows from card activity. Once resolved, HOW MANY of its matches_banned
 * fechas have already been served is never tracked as a counter either --
 * it's always recomputed from the team's own match calendar (see
 * teamMatchSequence()/matchesServedCount()): a fecha counts as served once
 * the corresponding match in the sequence actually finishes, whether the
 * organizer thinks to mark anything or not.
 *
 * $player_id and $coach_id are both nullable and mutually exclusive --
 * exactly one is ever set, same "nullable columns + app-level XOR" pattern
 * MatchEvent already uses. $team_id is denormalized from that subject's
 * team at creation time, same reasoning as MatchEvent::$team_id.
 *
 * @property int $id
 * @property int $match_id
 * @property int $match_event_id
 * @property int $team_id
 * @property int|null $player_id
 * @property int|null $coach_id
 * @property SanctionType $type
 * @property SanctionStatus $status
 * @property int|null $matches_banned
 * @property float|null $fine_amount
 * @property string|null $resolution_notes
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['match_id', 'match_event_id', 'team_id', 'player_id', 'coach_id', 'type', 'status', 'matches_banned', 'fine_amount', 'resolution_notes', 'resolved_at'])]
class Sanction extends Model
{
    /** @use HasFactory<SanctionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => SanctionType::class,
            'status' => SanctionStatus::class,
            'fine_amount' => 'decimal:2',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TournamentMatch, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(TournamentMatch::class, 'match_id');
    }

    /**
     * @return BelongsTo<MatchEvent, $this>
     */
    public function matchEvent(): BelongsTo
    {
        return $this->belongsTo(MatchEvent::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<Player, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * @return BelongsTo<Coach, $this>
     */
    public function coach(): BelongsTo
    {
        return $this->belongsTo(Coach::class);
    }

    /**
     * Pure classification of what auto-managed Sanction (if any) a
     * subject's card tally in one match should produce -- doesn't touch the
     * database, see SanctionService for how this gets applied. Mirrors the
     * interpretation MatchEventController::cascadeCardDeletion() already
     * committed to: 2 yellow cards + 1 red card is read as one linked
     * expulsion (the red being the automatic consequence of the second
     * yellow), not two separate offenses -- so it maps to the SAME
     * double_yellow outcome a bare 2-yellows tally does. Any red card
     * recorded with FEWER than 2 yellows for that subject is read as a
     * genuine straight/direct red instead.
     *
     * The card-ceiling validation rule already enforced in
     * MatchEventRequest/MatchEventBatchRequest guarantees $yellowCount is
     * never more than 2 and $redCount never more than 1 for one subject in
     * one match, so those are the only tallies this ever has to classify.
     */
    public static function classifyCardTally(int $yellowCount, int $redCount): ?SanctionType
    {
        if ($yellowCount >= 2) {
            return SanctionType::DoubleYellow;
        }

        if ($redCount >= 1) {
            return SanctionType::RedCard;
        }

        return null;
    }

    /**
     * Whether $subjectId (a player or coach id) is currently blocked from
     * events in $matchId by ANY of their sanctions -- see blocksMatch() for
     * what "blocked" means for one sanction. Used by both MatchEventRequest
     * and MatchEventBatchRequest so a suspended subject can't get new
     * events registered in a match their suspension actually covers,
     * mirroring the "Jugadores no disponibles" panel
     * TournamentMatchController::edit() builds with the same check.
     */
    public static function currentlyBlocks(string $subjectColumn, int $subjectId, int $matchId): bool
    {
        return static::query()
            ->where($subjectColumn, $subjectId)
            ->get()
            ->contains(fn (self $sanction): bool => $sanction->blocksMatch($matchId));
    }

    /**
     * Display name for whoever this sanction is actually about -- same
     * convention as MatchEvent::subjectLabel().
     */
    public function subjectLabel(): string
    {
        if ($this->coach_id !== null) {
            return __('DT').': '.$this->coach->full_name;
        }

        return $this->player->full_name;
    }

    public function isPending(): bool
    {
        return $this->status === SanctionStatus::Pending;
    }

    public function isResolved(): bool
    {
        return $this->status === SanctionStatus::Resolved;
    }

    /**
     * Resolved, with fechas assigned, and not all of them served yet
     * (matchesServedCount() < matches_banned) -- the one state that
     * actually means "suspended right now". A resolved sanction with
     * matches_banned still null shouldn't happen (resolving always sets
     * it), but is treated as not-active rather than throwing.
     */
    public function isActive(): bool
    {
        return $this->isResolved() && $this->matches_banned !== null && $this->matchesServedCount() < $this->matches_banned;
    }

    public function isFulfilled(): bool
    {
        return $this->isResolved() && $this->matches_banned !== null && $this->matchesServedCount() >= $this->matches_banned;
    }

    /**
     * Whether this sanction, on its own, still keeps its subject out of
     * SOME match -- always true while Pending (no known end yet), true
     * while Resolved and isActive(). Doesn't say WHICH match; see
     * blocksMatch() for that.
     */
    public function stillOwesFechas(): bool
    {
        return $this->isPending() || $this->isActive();
    }

    /**
     * Every match this sanction's team plays or has played, across the
     * WHOLE tournament (every phase) -- ordered the same way the
     * phase/bracket structure itself is ordered: by CompetitionPhase's own
     * `order` column (league before playoffs before a final round, etc.),
     * then by `round_number` within that phase (matchday 1 before matchday
     * 2, quarterfinals before semifinals), then by id as the last tiebreak
     * for two fixtures scheduled in the same round. Both ordering fields
     * are the SAME ones LeagueScheduleService/KnockoutBracketService
     * already use to build the calendar/bracket in the first place --
     * nothing here is invented just for sanctions. A cancelled match is
     * left out entirely: it never happened, so it can neither serve a
     * fecha nor occupy one.
     *
     * @return Collection<int, TournamentMatch>
     */
    public function teamMatchSequence(): Collection
    {
        return TournamentMatch::query()
            ->where(function (Builder $query): void {
                $query->where('home_team_id', $this->team_id)->orWhere('away_team_id', $this->team_id);
            })
            ->where('status', '!=', MatchStatus::Cancelled)
            ->with('competitionPhase')
            ->get()
            ->sort(fn (TournamentMatch $a, TournamentMatch $b): int => [$a->competitionPhase->order, $a->round_number ?? PHP_INT_MAX, $a->id]
                <=> [$b->competitionPhase->order, $b->round_number ?? PHP_INT_MAX, $b->id])
            ->values();
    }

    /**
     * The subset of teamMatchSequence() that comes strictly AFTER the match
     * that originated this sanction -- "the following matches" a fecha can
     * ever be served in. Empty if the origin match can't be found in the
     * sequence (shouldn't happen: it's always one of the team's own
     * matches).
     *
     * @return Collection<int, TournamentMatch>
     */
    public function matchesAfterOrigin(): Collection
    {
        $sequence = $this->teamMatchSequence();
        $originIndex = $sequence->search(fn (TournamentMatch $match): bool => $match->id === $this->match_id);

        if ($originIndex === false) {
            return new Collection;
        }

        return $sequence->slice($originIndex + 1)->values();
    }

    /**
     * The specific matches this sanction's fechas are actually served in --
     * the first matches_banned matches after the origin, in order. Empty
     * while still Pending (the duration isn't known yet, see rule 2 on
     * straight reds). Once matches_banned matches have been played, later
     * matches fall outside this window and are never blocked, automatically
     * -- there's no manual "mark this fecha served" step.
     *
     * @return Collection<int, TournamentMatch>
     */
    public function servingWindowMatches(): Collection
    {
        if (! $this->isResolved() || $this->matches_banned === null) {
            return new Collection;
        }

        return $this->matchesAfterOrigin()->take($this->matches_banned)->values();
    }

    /**
     * How many of this sanction's fechas have actually been served --
     * always computed from the team's calendar (a servingWindowMatches()
     * entry counts once it's actually Finished), never a stored counter.
     */
    public function matchesServedCount(): int
    {
        return $this->servingWindowMatches()->where('status', MatchStatus::Finished)->count();
    }

    /**
     * Null while pending (there's nothing to count down yet), otherwise how
     * many fechas are still owed.
     */
    public function remainingMatches(): ?int
    {
        if (! $this->isResolved() || $this->matches_banned === null) {
            return null;
        }

        return max($this->matches_banned - $this->matchesServedCount(), 0);
    }

    /**
     * Whether this sanction keeps its subject out of $matchId specifically
     * -- never true for the match that originated it (the subject played
     * that one in full, up until the expulsion). While still Pending, true
     * for every one of the team's matches after the origin (an unresolved
     * red's end isn't known yet, so it provisionally covers all of them).
     * Once Resolved, true only while $matchId falls inside
     * servingWindowMatches() -- the first matches_banned matches after the
     * origin -- so a match from BEFORE the origin, or one past the window,
     * is never blocked.
     */
    public function blocksMatch(int $matchId): bool
    {
        if ($matchId === $this->match_id) {
            return false;
        }

        if ($this->isPending()) {
            return $this->matchesAfterOrigin()->contains('id', $matchId);
        }

        return $this->servingWindowMatches()->contains('id', $matchId);
    }

    public function stateLabel(): string
    {
        if ($this->isPending()) {
            return __('Pendiente de resolución');
        }

        if ($this->isFulfilled()) {
            return __('Sanción cumplida');
        }

        return __('Cumpliendo sanción (:served de :total fechas)', [
            'served' => $this->matchesServedCount(),
            'total' => $this->matches_banned,
        ]);
    }

    /**
     * "Fecha K de N" for $matchId specifically -- K being its own 1-based
     * position in servingWindowMatches() -- frozen to that match forever,
     * instead of stateLabel()'s today's-overall-total. The FIRST window
     * match is always "1 de N", the LAST is always "N de N": that match IS
     * that fecha, whether or not some OTHER later match has since finished
     * too, so this never flips to "Sanción cumplida" the way stateLabel()
     * eventually does. Used wherever a sanction is shown alongside one
     * particular match -- the "Jugadores no disponibles" panel, the
     * serving-window list on the sanction's own page -- never for the
     * sanction's own general status (stateLabel() is still right for that,
     * e.g. on the sanctions index).
     */
    public function stateLabelForMatch(int $matchId): string
    {
        if ($this->isPending()) {
            return __('Pendiente de resolución');
        }

        $position = $this->servingWindowMatches()->search(fn (TournamentMatch $match): bool => $match->id === $matchId);
        $fechaNumber = $position === false ? $this->matchesServedCount() : $position + 1;

        return __('Cumpliendo sanción (:current de :total fechas)', [
            'current' => $fechaNumber,
            'total' => $this->matches_banned,
        ]);
    }
}
