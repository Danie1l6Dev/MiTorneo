<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchStatus;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Player;
use App\Models\Setting;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\StandingsService;
use App\Services\TeamExpulsionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TeamExpulsionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tournament, 1: Category, 2: CompetitionPhase, 3: Team, 4: Team}
     */
    private function makeLeague(User $user): array
    {
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $teamA = Team::factory()->for($tournament)->for($category)->create();
        $teamB = Team::factory()->for($tournament)->for($category)->create();

        return [$tournament, $category, $phase, $teamA, $teamB];
    }

    public function test_expelling_a_team_forfeits_its_unplayed_matches_and_awards_the_opponent(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $phase, $teamA, $teamB] = $this->makeLeague($user);
        $teamC = Team::factory()->for($tournament)->for($category)->create();

        // Already played -- must stay untouched.
        $playedMatch = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'status' => MatchStatus::Finished,
            'home_score' => 2,
            'away_score' => 1,
        ]);

        // Not played yet -- must become a 0-3 walkover loss for teamA.
        $pendingMatch = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $teamC->id,
            'away_team_id' => $teamA->id,
            'status' => MatchStatus::Scheduled,
        ]);

        $this->actingAs($user)->post(route('tournaments.categories.teams.expel.store', [$tournament, $category, $teamA]), [
            'reason' => 'Agresión al árbitro tras el partido.',
        ])->assertRedirect(route('tournaments.categories.show', [$tournament, $category]));

        $this->assertTrue($teamA->fresh()->isExpelledFrom($tournament->fresh()));
        $this->assertSame('Agresión al árbitro tras el partido.', $teamA->expulsionReasonFor($tournament));

        $playedMatch->refresh();
        $this->assertSame(2, $playedMatch->home_score);
        $this->assertSame(1, $playedMatch->away_score);
        $this->assertFalse($playedMatch->is_walkover);

        $pendingMatch->refresh();
        $this->assertSame(MatchStatus::Finished, $pendingMatch->status);
        $this->assertSame(3, $pendingMatch->home_score);
        $this->assertSame(0, $pendingMatch->away_score);
        $this->assertTrue($pendingMatch->is_walkover);
        $this->assertSame($teamA->id, $pendingMatch->walkover_team_id);

        $tables = app(StandingsService::class)->tablesForPhase($phase->fresh());
        $rows = collect($tables[0]['rows'])->keyBy(fn (array $row) => $row['team']->id);

        $this->assertSame(3, $rows[$teamC->id]['points']);
        $this->assertSame(3, $rows[$teamC->id]['goals_for']);
        $this->assertSame(0, $rows[$teamC->id]['goals_against']);
    }

    public function test_expelling_a_team_can_attach_a_resolution_pdf_when_the_feature_is_enabled(): void
    {
        Setting::current()->update(['sanction_pdf_uploads_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        [$tournament, $category, , $teamA] = $this->makeLeague($user);

        $pdf = UploadedFile::fake()->create('resolucion.pdf', 100, 'application/pdf');

        $this->actingAs($user)->post(route('tournaments.categories.teams.expel.store', [$tournament, $category, $teamA]), [
            'resolution_pdf' => $pdf,
        ])->assertRedirect(route('tournaments.categories.show', [$tournament, $category]));

        $pdfPath = $teamA->fresh()->expulsionResolutionPdfPathFor($tournament->fresh());
        $this->assertNotNull($pdfPath);
        $this->assertNull($teamA->expulsionReasonFor($tournament));
        Storage::disk('public')->assertExists($pdfPath);
    }

    public function test_a_resolution_pdf_sent_while_expelling_with_the_feature_disabled_is_ignored(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        [$tournament, $category, , $teamA] = $this->makeLeague($user);

        $pdf = UploadedFile::fake()->create('resolucion.pdf', 100, 'application/pdf');

        $this->actingAs($user)->post(route('tournaments.categories.teams.expel.store', [$tournament, $category, $teamA]), [
            'reason' => 'Motivo en texto.',
            'resolution_pdf' => $pdf,
        ])->assertRedirect(route('tournaments.categories.show', [$tournament, $category]));

        $this->assertNull($teamA->fresh()->expulsionResolutionPdfPathFor($tournament->fresh()));
        $this->assertSame('Motivo en texto.', $teamA->expulsionReasonFor($tournament));
        Storage::disk('public')->assertDirectoryEmpty('resoluciones');
    }

    public function test_reverting_an_expulsion_deletes_its_resolution_pdf_from_disk(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        [$tournament, $category, , $teamA] = $this->makeLeague($user);

        $pdfPath = UploadedFile::fake()->create('resolucion.pdf', 100, 'application/pdf')->store('resoluciones', 'public');
        app(TeamExpulsionService::class)->expel($teamA, $tournament, null, $pdfPath);

        Storage::disk('public')->assertExists($pdfPath);

        app(TeamExpulsionService::class)->revert($teamA, $tournament);

        Storage::disk('public')->assertMissing($pdfPath);
        $this->assertNull($teamA->fresh()->expulsionResolutionPdfPathFor($tournament->fresh()));
    }

    // ── Página de detalle de la expulsión ───────────────────────────────

    public function test_organizer_can_view_the_expulsion_detail_page(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, , $teamA] = $this->makeLeague($user);

        app(TeamExpulsionService::class)->expel($teamA, $tournament, 'Agresión al árbitro.');

        $this->actingAs($user)->get(route('tournaments.categories.teams.expulsion.show', [$tournament, $category, $teamA]))
            ->assertOk()
            ->assertSee($teamA->name)
            ->assertSee('Agresión al árbitro.');
    }

    public function test_the_expulsion_detail_page_is_a_404_for_a_team_that_was_never_expelled(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, , $teamA] = $this->makeLeague($user);

        $this->actingAs($user)->get(route('tournaments.categories.teams.expulsion.show', [$tournament, $category, $teamA]))
            ->assertNotFound();
    }

    public function test_organizer_can_replace_an_expulsions_resolution_pdf(): void
    {
        Setting::current()->update(['sanction_pdf_uploads_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        [$tournament, $category, , $teamA] = $this->makeLeague($user);
        app(TeamExpulsionService::class)->expel($teamA, $tournament, 'Motivo original.');

        $pdf = UploadedFile::fake()->create('resolucion.pdf', 100, 'application/pdf');

        $this->actingAs($user)->patch(route('tournaments.categories.teams.expel.resolution-pdf.update', [$tournament, $category, $teamA]), [
            'resolution_pdf' => $pdf,
        ])->assertRedirect();

        $pdfPath = $teamA->fresh()->expulsionResolutionPdfPathFor($tournament->fresh());
        $this->assertNotNull($pdfPath);
        $this->assertNull($teamA->expulsionReasonFor($tournament));
        Storage::disk('public')->assertExists($pdfPath);
    }

    public function test_organizer_can_remove_an_expulsions_resolution_pdf(): void
    {
        Setting::current()->update(['sanction_pdf_uploads_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        [$tournament, $category, , $teamA] = $this->makeLeague($user);
        $pdfPath = UploadedFile::fake()->create('resolucion.pdf', 100, 'application/pdf')->store('resoluciones', 'public');
        app(TeamExpulsionService::class)->expel($teamA, $tournament, null, $pdfPath);

        $this->actingAs($user)->delete(route('tournaments.categories.teams.expel.resolution-pdf.destroy', [$tournament, $category, $teamA]))
            ->assertRedirect();

        $this->assertNull($teamA->fresh()->expulsionResolutionPdfPathFor($tournament->fresh()));
        Storage::disk('public')->assertMissing($pdfPath);
    }

    public function test_a_user_cannot_manage_another_users_expulsion_resolution_pdf(): void
    {
        Setting::current()->update(['sanction_pdf_uploads_enabled' => true]);
        Storage::fake('public');

        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        [$tournament, $category, , $teamA] = $this->makeLeague($owner);
        $pdfPath = UploadedFile::fake()->create('resolucion.pdf', 100, 'application/pdf')->store('resoluciones', 'public');
        app(TeamExpulsionService::class)->expel($teamA, $tournament, null, $pdfPath);

        $this->actingAs($intruder)->get(route('tournaments.categories.teams.expulsion.show', [$tournament, $category, $teamA]))
            ->assertForbidden();

        $this->actingAs($intruder)->delete(route('tournaments.categories.teams.expel.resolution-pdf.destroy', [$tournament, $category, $teamA]))
            ->assertForbidden();

        Storage::disk('public')->assertExists($pdfPath);
    }

    // ── Estado de la expulsión (pendiente/activa/cumplida) ───────────────

    public function test_an_expulsion_with_no_resolution_stays_pending_even_once_the_category_is_finished(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $phase, $teamA, $teamB] = $this->makeLeague($user);

        TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'status' => MatchStatus::Finished,
            'home_score' => 2,
            'away_score' => 1,
        ]);

        app(TeamExpulsionService::class)->expel($teamA, $tournament, null);

        $this->assertTrue($teamA->isExpulsionPendingFor($tournament));
        $this->assertFalse($teamA->isExpulsionActiveFor($tournament));
        $this->assertFalse($teamA->isExpulsionFulfilledFor($tournament));
    }

    public function test_a_resolved_expulsion_stays_active_while_its_category_still_has_an_unfinished_match(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $phase, $teamA, $teamB] = $this->makeLeague($user);
        $teamC = Team::factory()->for($tournament)->for($category)->create();

        // A match between two OTHER teams in the same category -- teamA's
        // own expulsion never touches it, but it still keeps the category
        // from being "done".
        TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $teamB->id,
            'away_team_id' => $teamC->id,
            'status' => MatchStatus::Scheduled,
        ]);

        app(TeamExpulsionService::class)->expel($teamA, $tournament, 'Motivo.');

        $this->assertTrue($teamA->isExpulsionActiveFor($tournament));
        $this->assertFalse($teamA->isExpulsionFulfilledFor($tournament));
    }

    public function test_a_resolved_expulsion_is_fulfilled_once_every_match_of_the_category_is_finished(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $phase, $teamA, $teamB] = $this->makeLeague($user);

        TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'status' => MatchStatus::Finished,
            'home_score' => 2,
            'away_score' => 1,
        ]);

        app(TeamExpulsionService::class)->expel($teamA, $tournament, 'Motivo.');

        $this->assertTrue($teamA->isExpulsionFulfilledFor($tournament));
        $this->assertFalse($teamA->isExpulsionActiveFor($tournament));
    }

    public function test_a_new_phase_reopens_a_fulfilled_expulsion_back_to_active(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $phase, $teamA, $teamB] = $this->makeLeague($user);

        TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'status' => MatchStatus::Finished,
            'home_score' => 2,
            'away_score' => 1,
        ]);

        app(TeamExpulsionService::class)->expel($teamA, $tournament, 'Motivo.');
        $this->assertTrue($teamA->isExpulsionFulfilledFor($tournament));

        // A new phase for the same category (e.g. a knockout bracket once
        // the league wraps up) -- even before it has any matches generated,
        // the category isn't "done" anymore.
        CompetitionPhase::factory()->for($tournament)->for($category)->create();

        $this->assertFalse($teamA->isExpulsionFulfilledFor($tournament));
        $this->assertTrue($teamA->isExpulsionActiveFor($tournament));
    }

    public function test_expulsion_does_not_affect_the_same_clubs_team_in_another_category(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $phase, $teamA, $teamB] = $this->makeLeague($user);

        $otherCategory = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $otherPhase = CompetitionPhase::factory()->for($tournament)->for($otherCategory)->create();
        $siblingTeam = Team::factory()->for($tournament)->for($otherCategory)->create(['club_id' => $teamA->club_id]);
        $siblingOpponent = Team::factory()->for($tournament)->for($otherCategory)->create();

        $siblingMatch = TournamentMatch::factory()->for($otherPhase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $otherCategory->id,
            'home_team_id' => $siblingTeam->id,
            'away_team_id' => $siblingOpponent->id,
            'status' => MatchStatus::Scheduled,
        ]);

        TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'status' => MatchStatus::Scheduled,
        ]);

        $this->actingAs($user)->post(route('tournaments.categories.teams.expel.store', [$tournament, $category, $teamA]))
            ->assertRedirect();

        $siblingMatch->refresh();
        $this->assertSame(MatchStatus::Scheduled, $siblingMatch->status);
        $this->assertFalse($siblingMatch->is_walkover);
        $this->assertFalse($siblingTeam->isExpelledFrom($tournament));
    }

    public function test_reverting_an_expulsion_restores_the_forfeited_matches(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $phase, $teamA, $teamB] = $this->makeLeague($user);

        $pendingMatch = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'status' => MatchStatus::Scheduled,
        ]);

        app(TeamExpulsionService::class)->expel($teamA, $tournament, 'motivo');
        $this->assertTrue($teamA->isExpelledFrom($tournament));

        $this->actingAs($user)->delete(route('tournaments.categories.teams.expel.destroy', [$tournament, $category, $teamA]))
            ->assertRedirect(route('tournaments.categories.show', [$tournament, $category]));

        $this->assertFalse($teamA->fresh()->isExpelledFrom($tournament->fresh()));

        $pendingMatch->refresh();
        $this->assertSame(MatchStatus::Scheduled, $pendingMatch->status);
        $this->assertNull($pendingMatch->home_score);
        $this->assertNull($pendingMatch->away_score);
        $this->assertFalse($pendingMatch->is_walkover);
        $this->assertNull($pendingMatch->walkover_team_id);
    }

    public function test_both_teams_already_expelled_cancels_the_match_between_them_instead_of_a_walkover(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $phase, $teamA, $teamB] = $this->makeLeague($user);

        $service = app(TeamExpulsionService::class);
        $service->expel($teamA, $tournament, null);
        $service->expel($teamB, $tournament, null);

        // A fixture between the two, created only AFTER both were already
        // expelled (e.g. a bracket regenerated later) -- neither expel()
        // call above could have seen it. Re-running expel() for one of them
        // is what picks it up.
        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'status' => MatchStatus::Scheduled,
        ]);

        $service->expel($teamA, $tournament, null);

        $match->refresh();
        $this->assertSame(MatchStatus::Cancelled, $match->status);
        $this->assertFalse($match->is_walkover);
    }

    public function test_a_user_cannot_expel_a_team_in_another_users_tournament(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        [$tournament, $category, , $teamA] = $this->makeLeague($owner);

        $this->actingAs($intruder)->post(route('tournaments.categories.teams.expel.store', [$tournament, $category, $teamA]))
            ->assertForbidden();

        $this->assertFalse($teamA->fresh()->isExpelledFrom($tournament));
    }

    // ── Tabla de posiciones ──────────────────────────────────────────────

    public function test_an_expelled_team_ranks_last_despite_having_more_points(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $phase, $teamA, $teamB] = $this->makeLeague($user);
        $teamC = Team::factory()->for($tournament)->for($category)->create();

        // teamA racks up 6 points before being expelled -- well ahead of
        // teamB/teamC, who have none.
        TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'status' => MatchStatus::Finished,
            'home_score' => 2,
            'away_score' => 0,
        ]);

        TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $teamC->id,
            'away_team_id' => $teamA->id,
            'status' => MatchStatus::Finished,
            'home_score' => 0,
            'away_score' => 1,
        ]);

        app(TeamExpulsionService::class)->expel($teamA, $tournament, null);

        $tables = app(StandingsService::class)->tablesForPhase($phase->fresh());
        $rows = collect($tables[0]['rows']);
        $teamARow = $rows->firstWhere('team.id', $teamA->id);

        $this->assertSame(6, $teamARow['points']);
        $this->assertTrue($teamARow['expelled']);
        $this->assertSame($teamA->id, $rows->last()['team']->id);
        $this->assertNotSame($teamA->id, $rows->first()['team']->id);
        $this->assertFalse($rows->first()['expelled']);
    }

    // ── Bloqueo de partidos "Perdido por W" ──────────────────────────────

    public function test_a_walkover_match_rejects_result_event_and_lineup_changes(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $phase, $teamA, $teamB] = $this->makeLeague($user);

        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'status' => MatchStatus::Scheduled,
        ]);

        app(TeamExpulsionService::class)->expel($teamA, $tournament, null);
        $match->refresh();
        $this->assertTrue($match->is_walkover);

        $player = Player::factory()->for($teamB)->create();

        // teamA is home here and is the one expelled, so the walkover left
        // it 0-3 -- an attempted edit must leave that untouched.
        $this->actingAs($user)->patch(route('matches.result.update', $match), [
            'home_score' => 5, 'away_score' => 5,
        ])->assertRedirect(route('matches.edit', $match));
        $this->assertSame(0, $match->fresh()->home_score);
        $this->assertSame(3, $match->fresh()->away_score);

        $this->actingAs($user)->put(route('matches.update', $match), [
            'status' => 'scheduled',
        ])->assertRedirect(route('matches.edit', $match));
        $this->assertSame(MatchStatus::Finished, $match->fresh()->status);

        $this->actingAs($user)->patch(route('matches.reset', $match))
            ->assertRedirect(route('matches.edit', $match));
        $this->assertSame(0, $match->fresh()->home_score);

        $this->actingAs($user)->delete(route('matches.destroy', $match))
            ->assertRedirect(route('matches.edit', $match));
        $this->assertNotNull($match->fresh());

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'goal', 'player_id' => $player->id,
        ])->assertRedirect(route('matches.edit', $match));
        $this->assertDatabaseMissing('match_events', ['match_id' => $match->id]);

        $this->actingAs($user)->post(route('matches.lineups.store', $match), [
            'team_id' => $teamB->id,
            'player_ids' => [$player->id],
        ])->assertRedirect(route('matches.edit', $match));
        $this->assertDatabaseMissing('match_lineups', ['match_id' => $match->id, 'player_id' => $player->id]);
    }

    public function test_reverting_the_expulsion_unlocks_the_match_again(): void
    {
        $user = User::factory()->create();
        [$tournament, $category, $phase, $teamA, $teamB] = $this->makeLeague($user);

        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'status' => MatchStatus::Scheduled,
        ]);

        $service = app(TeamExpulsionService::class);
        $service->expel($teamA, $tournament, null);
        $service->revert($teamA, $tournament);

        $this->assertFalse($match->fresh()->is_walkover);

        $this->actingAs($user)->patch(route('matches.result.update', $match), [
            'home_score' => 4, 'away_score' => 1,
        ])->assertRedirect(route('matches.edit', $match));

        $this->assertSame(4, $match->fresh()->home_score);
    }
}
