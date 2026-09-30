<?php

namespace Tests\Feature\Tournaments;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\LeagueSchedule;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Referee;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\MatchResultsReportService;
use App\Services\PdfLetterheadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Match results PDFs: one match (full report), one jornada/round, a whole
 * phase, or every played match of a category. Optional data (referee,
 * date, events) must only ever appear when the match actually has it.
 */
class MatchResultsPdfTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, tournament: Tournament, category: Category, phase: CompetitionPhase, home: Team, away: Team, player: Player, played: TournamentMatch, pending: TournamentMatch}
     */
    private function makeLeague(): array
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create(['name' => 'Copa Riohacha']);
        $category = Category::factory()->for($tournament)->create(['name' => 'Sub-13']);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'name' => 'Liga']);
        $schedule = LeagueSchedule::factory()->for($phase, 'competitionPhase')->for($tournament)->create();

        $home = Team::factory()->for($tournament)->for($category)->create(['name' => 'Tigres FC']);
        $away = Team::factory()->for($tournament)->for($category)->create(['name' => 'Leones FC']);
        $player = Player::factory()->for($home)->create(['full_name' => 'Carlos Gómez', 'jersey_number' => 9]);

        $played = TournamentMatch::factory()->for($phase)->create([
            'league_schedule_id' => $schedule->id,
            'round_number' => 1,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => 2,
            'away_score' => 0,
            'status' => MatchStatus::Finished,
        ]);

        MatchEvent::factory()->count(2)->create([
            'match_id' => $played->id,
            'team_id' => $home->id,
            'player_id' => $player->id,
            'type' => MatchEventType::Goal,
        ]);

        $pending = TournamentMatch::factory()->for($phase)->create([
            'league_schedule_id' => $schedule->id,
            'round_number' => 2,
            'home_team_id' => $away->id,
            'away_team_id' => $home->id,
        ]);

        return compact('user', 'tournament', 'category', 'phase', 'home', 'away', 'player', 'played', 'pending');
    }

    private function renderResults(array $data, ?int $round = null, bool $onlyPlayed = false): string
    {
        $sections = app(MatchResultsReportService::class)->phaseSections($data['phase'], $round, $onlyPlayed);

        return view('pdf.match-results', [
            'tournament' => $data['tournament'],
            'meta' => ['Categoría' => $data['category']->name],
            'phases' => [['heading' => null, 'sections' => $sections]],
            ...app(PdfLetterheadService::class)->forUser($data['user']),
        ])->render();
    }

    public function test_every_export_downloads_a_pdf(): void
    {
        $data = $this->makeLeague();
        $this->actingAs($data['user']);

        foreach ([
            route('matches.pdf', $data['played']),
            route('phases.results.pdf', $data['phase']),
            route('phases.results.pdf', [$data['phase'], 'round' => 1]),
            route('tournaments.categories.results.pdf', [$data['tournament'], $data['category']]),
        ] as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('content-type'), $url);
        }
    }

    public function test_results_can_be_exported_by_calendar_day_across_jornadas(): void
    {
        $data = $this->makeLeague();
        // Jornada 1 played on the 12th; jornada 2's match was pulled forward and
        // also played that same day. A third one on the 19th stays out.
        $data['played']->update(['scheduled_at' => '2026-09-12 09:00:00']);
        $data['pending']->update(['scheduled_at' => '2026-09-12 11:00:00', 'status' => MatchStatus::Finished, 'home_score' => 1, 'away_score' => 1]);
        TournamentMatch::factory()->for($data['phase'])->create([
            'round_number' => 3,
            'home_team_id' => $data['home']->id,
            'away_team_id' => $data['away']->id,
            'scheduled_at' => '2026-09-19 09:00:00',
            'status' => MatchStatus::Finished,
            'home_score' => 3,
            'away_score' => 3,
        ]);

        $service = app(MatchResultsReportService::class);

        $this->assertSame(['2026-09-12', '2026-09-19'], $service->playedDays($data['tournament']));

        $phases = $service->tournamentDaySections($data['tournament'], '2026-09-12', '2026-09-18');

        $this->assertCount(1, $phases);
        $this->assertSame('sábado 12 de septiembre de 2026', $phases[0]['heading']);
        $this->assertSame('SUB-13', $phases[0]['sections'][0]['title']);
        $rows = $phases[0]['sections'][0]['blocks'][0]['rows'];
        $this->assertCount(2, $rows);
        $this->assertSame(['Jornada 1', 'Jornada 2'], array_column($rows, 'note'));

        $response = $this->actingAs($data['user'])->get(route('tournaments.results.pdf', [$data['tournament'], 'from' => '2026-09-12', 'to' => '2026-09-12']));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));

        $this->get(route('tournaments.results.pdf', [$data['tournament'], 'from' => '2026-01-01', 'to' => '2026-01-02']))->assertNotFound();
    }

    public function test_the_municipal_letterhead_account_can_export_too(): void
    {
        $data = $this->makeLeague();
        $data['user']->update(['email' => 'faudisp@uniguajira.edu.co']);

        $this->actingAs($data['user'])->get(route('matches.pdf', $data['played']))->assertOk();
        $this->actingAs($data['user'])->get(route('phases.results.pdf', $data['phase']))->assertOk();
    }

    public function test_other_organizers_cannot_export_someone_elses_results(): void
    {
        $data = $this->makeLeague();
        $this->actingAs(User::factory()->create());

        $this->get(route('matches.pdf', $data['played']))->assertForbidden();
        $this->get(route('phases.results.pdf', $data['phase']))->assertForbidden();
        $this->get(route('tournaments.categories.results.pdf', [$data['tournament'], $data['category']]))->assertForbidden();
    }

    public function test_a_round_that_does_not_exist_is_a_404(): void
    {
        $data = $this->makeLeague();

        $this->actingAs($data['user'])
            ->get(route('phases.results.pdf', [$data['phase'], 'round' => 99]))
            ->assertNotFound();
    }

    public function test_a_category_with_no_phase_in_that_tournament_is_a_404(): void
    {
        $data = $this->makeLeague();
        $otherCategory = Category::factory()->for($data['tournament'])->create();

        $this->actingAs($data['user'])
            ->get(route('tournaments.categories.results.pdf', [$data['tournament'], $otherCategory]))
            ->assertNotFound();
    }

    public function test_a_jornada_export_only_lists_that_jornada(): void
    {
        $data = $this->makeLeague();

        $html = $this->renderResults($data, round: 1);

        $this->assertStringContainsString('Jornada 1', $html);
        $this->assertStringNotContainsString('Jornada 2', $html);
        $this->assertStringContainsString('2 - 0', $html);
        $this->assertStringContainsString('CARLOS GÓMEZ (2 G)', $html);
    }

    public function test_the_category_export_only_lists_played_matches(): void
    {
        $data = $this->makeLeague();

        $html = $this->renderResults($data, onlyPlayed: true);

        $this->assertStringContainsString('Jornada 1', $html);
        $this->assertStringNotContainsString('Jornada 2', $html);
    }

    public function test_the_whole_phase_export_shows_unplayed_matches_with_their_status(): void
    {
        $data = $this->makeLeague();

        $html = $this->renderResults($data);

        $this->assertStringContainsString('Jornada 2', $html);
        $this->assertStringContainsString('Programado', $html);
    }

    public function test_referee_and_date_only_appear_for_matches_that_have_them(): void
    {
        $data = $this->makeLeague();

        $html = $this->renderResults($data);
        $this->assertStringNotContainsString('Árbitro', $html);
        $this->assertStringNotContainsString('Fecha:', $html);

        $referee = Referee::factory()->for($data['user'])->create(['full_name' => 'Pedro Pitazo']);
        $data['played']->update(['referee_id' => $referee->id, 'scheduled_at' => '2026-09-20 15:30:00']);

        $html = $this->renderResults($data);
        $this->assertStringContainsString('Fecha: 20/09/2026 03:30 PM · Árbitro: PEDRO PITAZO', $html);
        // Only the match that has them -- the unplayed one prints neither.
        $this->assertSame(1, substr_count($html, 'Árbitro:'));
    }

    public function test_the_single_match_report_skips_data_the_match_does_not_have(): void
    {
        $data = $this->makeLeague();

        $html = view('pdf.match-report', [
            ...app(MatchResultsReportService::class)->matchReport($data['played']),
            ...app(PdfLetterheadService::class)->forUser($data['user']),
        ])->render();

        $this->assertStringContainsString('Informe del partido', $html);
        $this->assertStringContainsString('Jornada 1', $html);
        $this->assertStringContainsString('Planteles', $html);
        $this->assertStringContainsString('CARLOS GÓMEZ', $html);
        $this->assertStringContainsString('Estadísticas', $html);
        $this->assertStringNotContainsString('Árbitro', $html);
        $this->assertStringNotContainsString('Fecha y hora', $html);
        $this->assertStringNotContainsString('Grupo:', $html);
        $this->assertStringNotContainsString('Sanciones originadas', $html);
    }

    public function test_the_calendar_shows_the_results_export_menu_and_the_standings_tab_keeps_only_its_own(): void
    {
        $data = $this->makeLeague();

        $this->actingAs($data['user'])
            ->get(route('phases.show', $data['phase']))
            ->assertOk()
            ->assertSee('Exportar resultados')
            ->assertSee('Fase completa')
            // Jornada 2 is still pending, so its programming sheet is offered.
            ->assertSee('Exportar programación')
            ->assertSee('Toda la categoría (partidos jugados)');

        $this->actingAs($data['user'])
            ->get(route('matches.edit', $data['played']))
            ->assertOk()
            ->assertSee('Exportar PDF')
            ->assertSee(str_replace('/', '\/', route('matches.pdf', $data['played'])), false);
    }

    public function test_the_calendar_offers_the_date_range_export_only_when_a_played_match_has_a_day(): void
    {
        $data = $this->makeLeague();

        $this->actingAs($data['user'])
            ->get(route('phases.show', $data['phase']))
            ->assertOk()
            ->assertDontSee('Resultados por rango de fechas');

        $data['played']->update(['scheduled_at' => '2026-09-12 09:00:00']);

        $this->actingAs($data['user'])
            ->get(route('phases.show', $data['phase']))
            ->assertOk()
            ->assertSee('Resultados por rango de fechas')
            ->assertSee('Por rango de fechas');
    }

    public function test_the_date_range_export_can_be_narrowed_to_one_category(): void
    {
        $data = $this->makeLeague();
        $data['played']->update(['scheduled_at' => '2026-09-12 09:00:00']);

        $otherCategory = Category::factory()->for($data['tournament'])->create(['name' => 'Sub-15']);
        $otherPhase = CompetitionPhase::factory()->for($data['tournament'])->for($otherCategory)->create(['type' => CompetitionPhaseType::League]);
        TournamentMatch::factory()->for($otherPhase)->create([
            'round_number' => 1,
            'home_team_id' => Team::factory()->for($data['tournament'])->for($otherCategory)->create()->id,
            'away_team_id' => Team::factory()->for($data['tournament'])->for($otherCategory)->create()->id,
            'scheduled_at' => '2026-09-12 11:00:00',
            'status' => MatchStatus::Finished,
            'home_score' => 1,
            'away_score' => 0,
        ]);

        $service = app(MatchResultsReportService::class);

        $all = $service->tournamentDaySections($data['tournament'], '2026-09-12', '2026-09-12');
        $one = $service->tournamentDaySections($data['tournament'], '2026-09-12', '2026-09-12', $data['category']);

        $this->assertCount(2, $all[0]['sections']);
        $this->assertCount(1, $one[0]['sections']);
        $this->assertSame(['2026-09-12'], $service->playedDays($data['tournament'], $data['category']));

        $this->actingAs($data['user'])
            ->get(route('tournaments.results.pdf', [$data['tournament'], 'category' => $data['category']->id, 'from' => '2026-09-12', 'to' => '2026-09-12']))
            ->assertOk();

        $foreign = Category::factory()->for(Tournament::factory()->for($data['user']))->create();
        $this->get(route('tournaments.results.pdf', [$data['tournament'], 'category' => $foreign->id, 'from' => '2026-09-12', 'to' => '2026-09-12']))->assertNotFound();
    }
}
