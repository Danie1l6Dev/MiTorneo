<?php

namespace Tests\Feature\Tournaments;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * x-ui.bracket-match-card only ever renders the decisive (second) leg of a
 * two-legged knockout cross -- checking hasGoalMismatch() on just that leg
 * silently missed a mismatch that happened in the FIRST (ida) leg entirely.
 * Both the card's own warning badge and the "¿Ida o vuelta?" picker modal
 * now check each leg independently.
 */
class TwoLeggedBracketGoalMismatchTest extends TestCase
{
    use RefreshDatabase;

    private const WARNING_TEXT = 'Los goles registrados como eventos no coinciden con el marcador.';

    /**
     * @return array{user: User, phase: CompetitionPhase, firstLeg: TournamentMatch, secondLeg: TournamentMatch}
     */
    private function makeTwoLeggedFinal(User $user): array
    {
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::Final]);
        $teamA = Team::factory()->for($tournament)->for($category)->create(['name' => 'Equipo A']);
        $teamB = Team::factory()->for($tournament)->for($category)->create(['name' => 'Equipo B']);

        $firstLeg = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'round_number' => 1,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'home_score' => 1,
            'away_score' => 0,
            'status' => MatchStatus::Finished,
        ]);

        $secondLeg = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'round_number' => 1,
            'first_leg_match_id' => $firstLeg->id,
            'home_team_id' => $teamB->id,
            'away_team_id' => $teamA->id,
            'home_score' => 0,
            'away_score' => 0,
            'status' => MatchStatus::Finished,
        ]);

        return compact('user', 'phase', 'firstLeg', 'secondLeg');
    }

    public function test_a_mismatch_in_the_ida_leg_alone_now_shows_the_warning(): void
    {
        $user = User::factory()->create();
        ['phase' => $phase] = $this->makeTwoLeggedFinal($user);

        // teamA's home_score in the ida leg says 1, but no goal event was
        // ever recorded for that side -- a mismatch that lives entirely in
        // the leg the bracket card never inspects on its own.
        $response = $this->actingAs($user)->get(route('phases.show', $phase));

        $response->assertOk();

        $content = $response->getContent();
        // "Partido de ida"/"Partido de vuelta" each appear exactly once on
        // the page (only inside the picker modal), so they're reliable
        // anchors -- unlike the warning text itself, which legitimately
        // also appears in the pre-existing mobile stacked view (one
        // x-ui.match-card per leg, already correct) and in this card's own
        // corner badge. Finding it strictly BETWEEN the two anchors proves
        // it's attached to the "Partido de ida" button specifically.
        $idaPos = strpos($content, 'Partido de ida');
        $vueltaPos = strpos($content, 'Partido de vuelta');

        $this->assertNotFalse($idaPos);
        $this->assertNotFalse($vueltaPos);

        $warningPos = strpos($content, self::WARNING_TEXT, $idaPos);

        $this->assertNotFalse($warningPos, 'Expected the mismatch warning to appear with the "Partido de ida" button.');
        $this->assertLessThan($vueltaPos, $warningPos);
    }

    public function test_a_mismatch_in_the_vuelta_leg_alone_still_shows_the_warning(): void
    {
        $user = User::factory()->create();
        ['phase' => $phase, 'secondLeg' => $secondLeg, 'firstLeg' => $firstLeg] = $this->makeTwoLeggedFinal($user);

        // Give the ida leg a matching goal event (no mismatch there)...
        $playerA = Player::factory()->for($firstLeg->homeTeam)->create();
        MatchEvent::factory()->create([
            'match_id' => $firstLeg->id,
            'team_id' => $firstLeg->home_team_id,
            'player_id' => $playerA->id,
            'type' => MatchEventType::Goal,
        ]);

        // ...then break the vuelta leg's own score/event tally instead.
        $secondLeg->update(['home_score' => 1]);

        $response = $this->actingAs($user)->get(route('phases.show', $phase));
        $response->assertOk();

        $content = $response->getContent();
        $vueltaPos = strpos($content, 'Partido de vuelta');
        $this->assertNotFalse($vueltaPos);

        // Same reasoning as the ida test: search starting from the unique
        // "Partido de vuelta" anchor, since the warning text also
        // legitimately appears earlier on the page (the pre-existing mobile
        // stacked view's own per-leg x-ui.match-card, already correct).
        $warningPos = strpos($content, self::WARNING_TEXT, $vueltaPos);

        $this->assertNotFalse($warningPos, 'Expected the mismatch warning to appear with the "Partido de vuelta" button.');
    }

    public function test_no_warning_when_neither_leg_has_a_mismatch(): void
    {
        $user = User::factory()->create();
        ['phase' => $phase, 'firstLeg' => $firstLeg] = $this->makeTwoLeggedFinal($user);

        $playerA = Player::factory()->for($firstLeg->homeTeam)->create();
        MatchEvent::factory()->create([
            'match_id' => $firstLeg->id,
            'team_id' => $firstLeg->home_team_id,
            'player_id' => $playerA->id,
            'type' => MatchEventType::Goal,
        ]);

        $this->actingAs($user)
            ->get(route('phases.show', $phase))
            ->assertOk()
            ->assertDontSee(self::WARNING_TEXT);
    }
}
