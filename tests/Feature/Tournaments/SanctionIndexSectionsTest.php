<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Enums\SanctionStatus;
use App\Enums\SanctionType;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Sanction;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sanctions index (pages/sanctions/index.blade.php) splits the
 * organizer's sanctions into three sections instead of one flat list:
 * "Faltan por resolver" (Pending), "Ya tienen resolución" (Resolved but
 * still being served -- Sanction::isActive()), and "Ya cumplidas" (Resolved
 * and fully served -- Sanction::isFulfilled()).
 */
class SanctionIndexSectionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Team, 1: TournamentMatch}
     */
    private function makeTeamWithOriginMatch(User $user): array
    {
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $team = Team::factory()->for($tournament)->for($category)->create();
        $opponent = Team::factory()->for($tournament)->for($category)->create();

        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
            'round_number' => 1,
            'status' => MatchStatus::Finished,
        ]);

        return [$team, $match];
    }

    private function makeFollowUpMatch(TournamentMatch $origin, int $roundNumber, MatchStatus $status): TournamentMatch
    {
        return TournamentMatch::factory()->for($origin->competitionPhase)->create([
            'tournament_id' => $origin->tournament_id,
            'category_id' => $origin->category_id,
            'home_team_id' => $origin->home_team_id,
            'away_team_id' => $origin->away_team_id,
            'round_number' => $roundNumber,
            'status' => $status,
        ]);
    }

    private function makeSanction(Team $team, TournamentMatch $match, SanctionStatus $status, ?int $matchesBanned = null, string $fullName = 'Jugador Sancionado'): Sanction
    {
        $player = Player::factory()->for($team)->create(['full_name' => $fullName]);

        $event = MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $team->id,
            'player_id' => $player->id,
            'type' => MatchEventType::RedCard,
        ]);

        return Sanction::factory()->create([
            'match_id' => $match->id,
            'match_event_id' => $event->id,
            'team_id' => $team->id,
            'player_id' => $player->id,
            'type' => SanctionType::RedCard,
            'status' => $status,
            'matches_banned' => $matchesBanned,
            'resolved_at' => $status === SanctionStatus::Resolved ? now() : null,
        ]);
    }

    // ── Faltan por resolver ──────────────────────────────────────────────

    public function test_a_pending_sanction_appears_in_the_faltan_por_resolver_section(): void
    {
        $user = User::factory()->create();
        [$team, $match] = $this->makeTeamWithOriginMatch($user);
        $this->makeSanction($team, $match, SanctionStatus::Pending, fullName: 'Pendiente Pérez');

        $response = $this->actingAs($user)->get(route('sanctions.index'));

        $response->assertOk()
            ->assertSeeTextInOrder([__('Faltan por resolver'), 'Pendiente Pérez'])
            ->assertViewHas('pendingSanctions', fn ($sanctions) => $sanctions->count() === 1)
            ->assertViewHas('activeSanctions', fn ($sanctions) => $sanctions->isEmpty())
            ->assertViewHas('fulfilledSanctions', fn ($sanctions) => $sanctions->isEmpty());
    }

    // ── Ya tienen resolución (activa, aún cumpliendo) ─────────────────────

    public function test_a_resolved_but_still_serving_sanction_appears_in_the_ya_tienen_resolucion_section(): void
    {
        $user = User::factory()->create();
        [$team, $match] = $this->makeTeamWithOriginMatch($user);
        // Not finished yet -- the single banned fecha hasn't been served.
        $this->makeFollowUpMatch($match, 2, MatchStatus::Scheduled);

        $this->makeSanction($team, $match, SanctionStatus::Resolved, matchesBanned: 1, fullName: 'Activo Gómez');

        $response = $this->actingAs($user)->get(route('sanctions.index'));

        $response->assertOk()
            ->assertSeeTextInOrder([__('Ya tienen resolución'), 'Activo Gómez'])
            ->assertViewHas('activeSanctions', fn ($sanctions) => $sanctions->count() === 1)
            ->assertViewHas('pendingSanctions', fn ($sanctions) => $sanctions->isEmpty())
            ->assertViewHas('fulfilledSanctions', fn ($sanctions) => $sanctions->isEmpty());
    }

    // ── Ya cumplidas ─────────────────────────────────────────────────────

    public function test_a_fully_served_sanction_appears_in_the_ya_cumplidas_section(): void
    {
        $user = User::factory()->create();
        [$team, $match] = $this->makeTeamWithOriginMatch($user);
        // Finished -- the single banned fecha has been served.
        $this->makeFollowUpMatch($match, 2, MatchStatus::Finished);

        $this->makeSanction($team, $match, SanctionStatus::Resolved, matchesBanned: 1, fullName: 'Cumplido Ruiz');

        $response = $this->actingAs($user)->get(route('sanctions.index'));

        $response->assertOk()
            ->assertSeeTextInOrder([__('Ya cumplidas'), 'Cumplido Ruiz'])
            ->assertViewHas('fulfilledSanctions', fn ($sanctions) => $sanctions->count() === 1)
            ->assertViewHas('pendingSanctions', fn ($sanctions) => $sanctions->isEmpty())
            ->assertViewHas('activeSanctions', fn ($sanctions) => $sanctions->isEmpty());
    }

    // ── Secciones vacías no se muestran ───────────────────────────────────

    public function test_a_section_with_no_sanctions_is_not_rendered(): void
    {
        $user = User::factory()->create();
        [$team, $match] = $this->makeTeamWithOriginMatch($user);
        $this->makeSanction($team, $match, SanctionStatus::Pending);

        $response = $this->actingAs($user)->get(route('sanctions.index'));

        $response->assertOk()
            ->assertSeeText(__('Faltan por resolver'))
            ->assertDontSeeText(__('Ya tienen resolución'))
            ->assertDontSeeText(__('Ya cumplidas'));
    }

    // ── Las tres secciones a la vez ───────────────────────────────────────

    public function test_all_three_sections_render_together_with_the_right_sanction_in_each(): void
    {
        $user = User::factory()->create();

        [$teamPending, $matchPending] = $this->makeTeamWithOriginMatch($user);
        $this->makeSanction($teamPending, $matchPending, SanctionStatus::Pending, fullName: 'Pendiente Pérez');

        [$teamActive, $matchActive] = $this->makeTeamWithOriginMatch($user);
        $this->makeFollowUpMatch($matchActive, 2, MatchStatus::Scheduled);
        $this->makeSanction($teamActive, $matchActive, SanctionStatus::Resolved, matchesBanned: 1, fullName: 'Activo Gómez');

        [$teamFulfilled, $matchFulfilled] = $this->makeTeamWithOriginMatch($user);
        $this->makeFollowUpMatch($matchFulfilled, 2, MatchStatus::Finished);
        $this->makeSanction($teamFulfilled, $matchFulfilled, SanctionStatus::Resolved, matchesBanned: 1, fullName: 'Cumplido Ruiz');

        $response = $this->actingAs($user)->get(route('sanctions.index'));

        $response->assertOk()
            ->assertViewHas('pendingSanctions', fn ($sanctions) => $sanctions->count() === 1)
            ->assertViewHas('activeSanctions', fn ($sanctions) => $sanctions->count() === 1)
            ->assertViewHas('fulfilledSanctions', fn ($sanctions) => $sanctions->count() === 1)
            ->assertSeeText('Pendiente Pérez')
            ->assertSeeText('Activo Gómez')
            ->assertSeeText('Cumplido Ruiz');
    }

    public function test_the_stat_cards_count_matches_the_sections(): void
    {
        $user = User::factory()->create();

        [$teamOne, $matchOne] = $this->makeTeamWithOriginMatch($user);
        $this->makeSanction($teamOne, $matchOne, SanctionStatus::Pending);

        [$teamTwo, $matchTwo] = $this->makeTeamWithOriginMatch($user);
        $this->makeSanction($teamTwo, $matchTwo, SanctionStatus::Pending);

        $response = $this->actingAs($user)->get(route('sanctions.index'));

        $response->assertOk()->assertSeeText('2');
    }

    // ── Seguridad/ownership ──────────────────────────────────────────────

    public function test_the_index_only_shows_the_authenticated_users_own_sanctions(): void
    {
        $owner = User::factory()->create();
        [$ownTeam, $ownMatch] = $this->makeTeamWithOriginMatch($owner);
        $this->makeSanction($ownTeam, $ownMatch, SanctionStatus::Pending, fullName: 'Propio Pérez');

        $otherUser = User::factory()->create();
        [$otherTeam, $otherMatch] = $this->makeTeamWithOriginMatch($otherUser);
        $this->makeSanction($otherTeam, $otherMatch, SanctionStatus::Pending, fullName: 'Ajeno Gómez');

        $response = $this->actingAs($owner)->get(route('sanctions.index'));

        $response->assertOk()
            ->assertSeeText('Propio Pérez')
            ->assertDontSeeText('Ajeno Gómez')
            ->assertViewHas('pendingSanctions', fn ($sanctions) => $sanctions->count() === 1);
    }

    public function test_a_guest_cannot_view_the_sanctions_index(): void
    {
        $this->get(route('sanctions.index'))->assertRedirect(route('login'));
    }
}
