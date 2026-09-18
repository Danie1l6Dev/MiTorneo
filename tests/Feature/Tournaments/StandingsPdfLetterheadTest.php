<?php

namespace Tests\Feature\Tournaments;

use App\Enums\CompetitionPhaseType;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The standings PDF is open to every organizer: the generic MiTorneo
 * letterhead by default, Faudis' LIFUTGUA one only for his account.
 */
class StandingsPdfLetterheadTest extends TestCase
{
    use RefreshDatabase;

    private function phaseFor(User $user): CompetitionPhase
    {
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create();

        return CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League]);
    }

    public function test_regular_organizer_can_export_a_phase_with_the_generic_letterhead(): void
    {
        $user = User::factory()->create();
        $phase = $this->phaseFor($user);

        $response = $this->actingAs($user)->get(route('phases.standings.pdf', $phase));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_regular_organizer_can_export_a_whole_tournament(): void
    {
        $user = User::factory()->create();
        $phase = $this->phaseFor($user);

        $this->actingAs($user)
            ->get(route('tournaments.standings.pdf', $phase->tournament))
            ->assertOk();
    }

    public function test_faudis_account_gets_the_municipal_letterhead(): void
    {
        $user = User::factory()->create(['email' => 'faudisp@uniguajira.edu.co']);
        $phase = $this->phaseFor($user);

        $this->assertTrue($user->usesMunicipalLetterhead());
        $this->actingAs($user)->get(route('phases.standings.pdf', $phase))->assertOk();
    }

    public function test_other_users_cannot_export_someone_elses_phase(): void
    {
        $phase = $this->phaseFor(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->get(route('phases.standings.pdf', $phase))
            ->assertForbidden();
    }
}
