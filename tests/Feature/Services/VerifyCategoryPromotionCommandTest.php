<?php

namespace Tests\Feature\Services;

use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Services\CategoryTournamentPromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T02-02 of docs/plan-reestructuracion/02-unificacion-categorias-torneo.md.
 */
class VerifyCategoryPromotionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_comparing_a_snapshot_against_itself_after_promotion_reports_no_differences(): void
    {
        $tournament = Tournament::factory()->create();
        $category = Category::factory()->for($tournament)->create();
        $phase = CompetitionPhase::factory()->for($category)->for($tournament)->create();
        $teamA = Team::factory()->for($category)->for($tournament)->create();
        $teamB = Team::factory()->for($category)->for($tournament)->create();
        TournamentMatch::factory()->for($phase, 'competitionPhase')->create([
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'home_score' => 3,
            'away_score' => 0,
        ]);

        $snapshotPath = tempnam(sys_get_temp_dir(), 'promo-snapshot');

        $this->artisan('tournaments:verify-category-promotion', ['--snapshot' => $snapshotPath])
            ->assertSuccessful();

        (new CategoryTournamentPromotionService)->run(execute: true);

        $this->artisan('tournaments:verify-category-promotion', ['--compare' => $snapshotPath])
            ->assertSuccessful();

        unlink($snapshotPath);
    }

    public function test_it_detects_a_changed_result(): void
    {
        $tournament = Tournament::factory()->create();
        $category = Category::factory()->for($tournament)->create();
        $phase = CompetitionPhase::factory()->for($category)->for($tournament)->create();
        $teamA = Team::factory()->for($category)->for($tournament)->create();
        $teamB = Team::factory()->for($category)->for($tournament)->create();
        $match = TournamentMatch::factory()->for($phase, 'competitionPhase')->create([
            'home_team_id' => $teamA->id,
            'away_team_id' => $teamB->id,
            'home_score' => 1,
            'away_score' => 1,
        ]);

        $snapshotPath = tempnam(sys_get_temp_dir(), 'promo-snapshot');
        $this->artisan('tournaments:verify-category-promotion', ['--snapshot' => $snapshotPath])->assertSuccessful();

        $match->update(['home_score' => 5]);

        $this->artisan('tournaments:verify-category-promotion', ['--compare' => $snapshotPath])
            ->assertFailed();

        unlink($snapshotPath);
    }
}
