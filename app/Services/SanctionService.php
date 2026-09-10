<?php

namespace App\Services;

use App\Enums\MatchEventType;
use App\Enums\SanctionStatus;
use App\Enums\SanctionType;
use App\Models\MatchEvent;
use App\Models\Sanction;
use App\Models\TournamentMatch;

/**
 * Keeps at most one Sanction per (match, subject) in sync with that
 * subject's current yellow/red MatchEvent tally in that match -- the only
 * place Sanction rows are created, replaced or removed from card activity.
 * Called by MatchEventController after every store/storeBatch/destroy that
 * touches a yellow or red card, so it never matters in what order the
 * underlying cards were entered or removed: syncForSubject() always
 * recomputes from whatever the DB currently holds, the same
 * order-independent philosophy already established for the card-ceiling
 * validation rules in MatchEventRequest/MatchEventBatchRequest.
 */
class SanctionService
{
    /**
     * @param  array{team_id: int, player_id: int|null, coach_id: int|null}  $subject  Same shape MatchEventController::resolveSubject() already produces.
     */
    public function syncForSubject(TournamentMatch $match, array $subject): void
    {
        $subjectColumn = $subject['coach_id'] !== null ? 'coach_id' : 'player_id';
        $subjectId = $subject['coach_id'] ?? $subject['player_id'];

        $yellowCount = $this->cardCount($match, $subjectColumn, $subjectId, MatchEventType::YellowCard);
        $redCount = $this->cardCount($match, $subjectColumn, $subjectId, MatchEventType::RedCard);

        $desiredType = Sanction::classifyCardTally($yellowCount, $redCount);

        $existing = Sanction::query()
            ->where('match_id', $match->id)
            ->where($subjectColumn, $subjectId)
            ->first();

        if ($existing !== null && ! $this->isAutoManageable($existing)) {
            // A red_card sanction the Comité Directivo already resolved --
            // never touched automatically again, whatever the card tally
            // does afterward (e.g. a backfilled second yellow, or a card
            // getting deleted). See Sanction's own docblock.
            return;
        }

        if ($existing !== null && $existing->type === $desiredType) {
            return;
        }

        $existing?->delete();

        if ($desiredType === null) {
            return;
        }

        $triggerEvent = MatchEvent::query()
            ->where('match_id', $match->id)
            ->where($subjectColumn, $subjectId)
            ->where('type', $desiredType === SanctionType::DoubleYellow ? MatchEventType::YellowCard : MatchEventType::RedCard)
            ->latest('id')
            ->first();

        if ($triggerEvent === null) {
            return;
        }

        Sanction::query()->create([
            'match_id' => $match->id,
            'match_event_id' => $triggerEvent->id,
            'team_id' => $subject['team_id'],
            'player_id' => $subject['player_id'],
            'coach_id' => $subject['coach_id'],
            'type' => $desiredType,
            // Rule 1: two yellows in the same match always cost exactly one
            // fecha, decided automatically, no committee step needed. Rule
            // 2: a straight red's duration is never assumed -- it stays
            // Pending until SanctionController::resolve() sets it.
            'status' => $desiredType === SanctionType::DoubleYellow ? SanctionStatus::Resolved : SanctionStatus::Pending,
            'matches_banned' => $desiredType === SanctionType::DoubleYellow ? 1 : null,
            'resolved_at' => $desiredType === SanctionType::DoubleYellow ? now() : null,
        ]);
    }

    /**
     * A double_yellow sanction is always auto-decided (fixed at 1 fecha, no
     * committee input possible) so it's always safe to replace. A red_card
     * sanction is only safe to replace while still Pending -- once the
     * committee has resolved it (fechas, and maybe a fine, on record), the
     * card tally that produced it is no longer allowed to overwrite it.
     */
    public function isAutoManageable(Sanction $sanction): bool
    {
        return $sanction->type === SanctionType::DoubleYellow || $sanction->status === SanctionStatus::Pending;
    }

    private function cardCount(TournamentMatch $match, string $subjectColumn, int $subjectId, MatchEventType $type): int
    {
        return MatchEvent::query()
            ->where('match_id', $match->id)
            ->where($subjectColumn, $subjectId)
            ->where('type', $type)
            ->count();
    }
}
