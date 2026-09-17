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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
 * @property string|null $resolution_pdf_path
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['match_id', 'match_event_id', 'team_id', 'player_id', 'coach_id', 'type', 'status', 'matches_banned', 'fine_amount', 'resolution_notes', 'resolution_pdf_path', 'resolved_at'])]
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

    /**
     * Public URL for the committee's resolution PDF, if one was attached
     * when this sanction was resolved (see Setting::sanctionPdfUploadsEnabled()
     * -- only possible while that flag was on). Null otherwise, including
     * for a sanction resolved with just resolution_notes.
     */
    public function resolutionPdfUrl(): ?string
    {
        return $this->resolution_pdf_path !== null
            ? Storage::disk('public')->url($this->resolution_pdf_path)
            : null;
    }

    /**
     * Filename offered to the browser when downloading resolutionPdfUrl(),
     * instead of the opaque storage path -- e.g.
     * "resolucion-sancion-jugador-juan-perez.pdf", or "...-dt-..." for a
     * coach. Always safe to call even if there's no PDF, but only ever used
     * from a view guarded by resolution_pdf_path being set.
     */
    public function resolutionPdfDownloadName(): string
    {
        $prefix = $this->coach_id !== null ? 'resolucion-sancion-dt' : 'resolucion-sancion-jugador';
        $subject = $this->coach_id !== null ? $this->coach->full_name : $this->player->full_name;

        return $prefix.'-'.Str::slug($subject).'.pdf';
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
     * Which Team ids this sanction's own subject (player or coach) actually
     * plays for -- what teamMatchSequence() searches matches over. A
     * suspension belongs to the SUBJECT, not to the team they happened to
     * be on when the card was shown (see this class's own docblock): a
     * player promoted to an older category's team mid-suspension, or one
     * who's since joined a second plantel, must keep owing fechas there
     * too. Player::allTeams() already covers exactly that -- the legacy
     * $team_id plus every player_team link, i.e. every plantel this player
     * is currently rostered on, regardless of category/club. The origin
     * $team_id is always unioned in even if the player has since left that
     * team entirely: matchesAfterOrigin() locates the origin match by id
     * inside teamMatchSequence(), so dropping it from the id list would
     * make the origin match itself unfindable and silently break every
     * fechas calculation for this sanction.
     *
     * A coach has no equivalent multi-team roster -- see Coach's own
     * docblock, one Coach row per team, never shared across teams -- so a
     * coach's sequence still comes from their single $team_id alone.
     *
     * @return list<int>
     */
    public function subjectTeamIds(): array
    {
        if ($this->coach_id !== null) {
            return [$this->team_id];
        }

        return array_values($this->player->allTeams()->pluck('id')->push($this->team_id)->unique()->map(fn (int $id): int => $id)->all());
    }

    /**
     * Every match this sanction's subject plays or has played -- across
     * every team they're rostered on (see subjectTeamIds()) and every
     * tournament those teams enter, not just the one tournament that
     * originated this sanction. That's what lets an unfinished suspension
     * (not enough matches left in the origin tournament to serve all its
     * fechas) carry over into whatever tournament/category the subject
     * next appears in, instead of quietly going unserved forever.
     *
     * Ordered chronologically: by the owning Tournament's own creation
     * order first (organizers create next season's tournament after the
     * current one, so creation order tracks real-world sequence -- there's
     * no structured tournament start date to use instead), then, same as
     * before within one tournament, by CompetitionPhase's own `order`
     * column (league before playoffs before a final round, etc.), then by
     * `round_number` within that phase (matchday 1 before matchday 2,
     * quarterfinals before semifinals), then by id as the last tiebreak for
     * two fixtures scheduled in the same round. Those three intra-tournament
     * fields are the SAME ones LeagueScheduleService/KnockoutBracketService
     * already use to build the calendar/bracket in the first place --
     * nothing here is invented just for sanctions. A cancelled match is
     * left out entirely: it never happened, so it can neither serve a
     * fecha nor occupy one.
     *
     * @return Collection<int, TournamentMatch>
     */
    public function teamMatchSequence(): Collection
    {
        $teamIds = $this->subjectTeamIds();

        return TournamentMatch::query()
            ->where(function (Builder $query) use ($teamIds): void {
                $query->whereIn('home_team_id', $teamIds)->orWhereIn('away_team_id', $teamIds);
            })
            ->where('status', '!=', MatchStatus::Cancelled)
            ->with(['competitionPhase', 'tournament'])
            ->get()
            ->sort(fn (TournamentMatch $a, TournamentMatch $b): int => [
                $a->tournament->created_at->timestamp, $a->tournament_id, $a->competitionPhase->order, $a->round_number ?? PHP_INT_MAX, $a->id,
            ] <=> [
                $b->tournament->created_at->timestamp, $b->tournament_id, $b->competitionPhase->order, $b->round_number ?? PHP_INT_MAX, $b->id,
            ])
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
