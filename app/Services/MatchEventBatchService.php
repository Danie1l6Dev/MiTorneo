<?php

namespace App\Services;

use App\Enums\MatchEventType;
use App\Models\Coach;
use App\Models\Player;
use App\Models\TournamentMatch;
use Illuminate\Support\Facades\DB;

/**
 * Persists an already-validated batch of quick-add events (see
 * ValidatesQueuedMatchEvents) for one match: one plain row per queued item,
 * then one SanctionService sync per carded subject. Shared by the
 * "Guardar eventos" submit (MatchEventController::storeBatch()) and the
 * result submit (MatchResultController), which saves any still-queued
 * events together with the score.
 */
class MatchEventBatchService
{
    public function __construct(private SanctionService $sanctions) {}

    /**
     * @param  array<int, array{type: string, player_id?: int|string|null, coach_id?: int|string|null}>  $events
     * @return int How many events were created.
     */
    public function store(TournamentMatch $match, array $events): int
    {
        if ($events === []) {
            return 0;
        }

        $events = collect($events);
        $players = Player::query()->whereIn('id', $events->pluck('player_id')->filter()->unique())->get()->keyBy('id');
        $coaches = Coach::query()->whereIn('id', $events->pluck('coach_id')->filter()->unique())->get()->keyBy('id');

        DB::transaction(function () use ($match, $events, $players, $coaches): void {
            // Keyed by "player:{id}"/"coach:{id}" so every subject that
            // received a card in this batch only gets synced once, after all
            // of the batch's events are actually saved -- SanctionService
            // always recomputes from the DB, so syncing mid-batch would just
            // redo the same work for nothing.
            $cardSubjects = [];

            foreach ($events as $eventData) {
                $coach = ! empty($eventData['coach_id']) ? $coaches->get($eventData['coach_id']) : null;

                // A player's side comes from THIS match's eligibility (see
                // TournamentMatch::eligibleTeamIdForPlayer()), not their own
                // team_id -- a play-up player's points at a younger team.
                $subject = $coach !== null
                    ? ['team_id' => $coach->team_id, 'player_id' => null, 'coach_id' => $coach->id]
                    : (function () use ($match, $players, $eventData): array {
                        $player = $players->get($eventData['player_id']);

                        return ['team_id' => $match->eligibleTeamIdForPlayer($player) ?? $player->team_id, 'player_id' => $player->id, 'coach_id' => null];
                    })();

                $type = MatchEventType::from($eventData['type']);

                $match->events()->create([...$subject, 'type' => $type]);

                if (in_array($type, [MatchEventType::YellowCard, MatchEventType::RedCard], true)) {
                    $key = $subject['coach_id'] !== null ? "coach:{$subject['coach_id']}" : "player:{$subject['player_id']}";
                    $cardSubjects[$key] = $subject;
                }
            }

            foreach ($cardSubjects as $subject) {
                $this->sanctions->syncForSubject($match, $subject);
            }
        });

        return $events->count();
    }
}
