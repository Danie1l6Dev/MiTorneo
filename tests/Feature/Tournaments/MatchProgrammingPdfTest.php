<?php

namespace Tests\Feature\Tournaments;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchStatus;
use App\Models\Category;
use App\Models\CompetitionPhase;
use App\Models\Group;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Models\Venue;
use App\Services\MatchProgrammingReportService;
use App\Services\PdfLetterheadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The official programming sheet: the chosen fechas' pending league
 * matches, across every category of the tournament (or just one), laid
 * out fecha > category > venue + day.
 */
class MatchProgrammingPdfTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, tournament: Tournament, sub13: Category, sub15: Category}
     */
    private function makeTournament(): array
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create(['name' => 'Copa Maicao']);

        $sub13 = Category::factory()->for($tournament)->create(['name' => 'Sub-13', 'birth_year_to' => 2013]);
        $sub15 = Category::factory()->for($tournament)->create(['name' => 'Sub-15', 'birth_year_to' => 2011]);

        $this->match($tournament, $sub13, 'Tigres', 'Leones', round: 5, at: '2026-09-12 07:30:00', venue: 'Cancha Parque Boscán');
        $this->match($tournament, $sub13, 'Osos', 'Lobos', round: 5, at: '2026-09-12 09:00:00', venue: 'Cancha Los Ídolos');
        $this->match($tournament, $sub15, 'Panteras', 'Halcones', round: 5, at: '2026-09-13 15:00:00', group: 'Grupo A');
        $this->match($tournament, $sub13, 'Pumas', 'Cóndores', round: 5, at: null);
        $this->match($tournament, $sub13, 'Otra', 'Fecha', round: 6, at: '2026-09-19 09:00:00');
        // Already played / a knockout match: never on the sheet.
        $this->match($tournament, $sub13, 'Jugado', 'Ya', round: 5, at: '2026-09-12 09:00:00', status: MatchStatus::Finished);
        $this->match($tournament, $sub15, 'Cuartos', 'Final', round: 5, at: '2026-09-12 10:00:00', type: CompetitionPhaseType::Knockout);

        return compact('user', 'tournament', 'sub13', 'sub15');
    }

    private function match(Tournament $tournament, Category $category, string $home, string $away, int $round, ?string $at, MatchStatus $status = MatchStatus::Scheduled, ?string $group = null, CompetitionPhaseType $type = CompetitionPhaseType::League, ?string $venue = null): void
    {
        $phase = CompetitionPhase::query()->where(['tournament_id' => $tournament->id, 'category_id' => $category->id, 'type' => $type])->first()
            ?? CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => $type]);

        TournamentMatch::factory()->for($phase)->create([
            'home_team_id' => Team::factory()->for($tournament)->for($category)->create(['name' => $home])->id,
            'away_team_id' => Team::factory()->for($tournament)->for($category)->create(['name' => $away])->id,
            'group_id' => $group ? Group::factory()->for($tournament)->for($category)->create(['name' => $group])->id : null,
            'round_number' => $round,
            'scheduled_at' => $at,
            'venue_id' => $venue ? (Venue::query()->where(['user_id' => $tournament->user_id, 'name' => mb_strtoupper($venue)])->first() ?? Venue::factory()->create(['user_id' => $tournament->user_id, 'name' => $venue]))->id : null,
            'status' => $status,
        ]);
    }

    /**
     * @param  list<int>|null  $rounds
     */
    private function render(array $data, ?array $rounds, ?Category $category = null): string
    {
        return view('pdf.match-programming', [
            'tournament' => $data['tournament'],
            'category' => $category,
            'sections' => app(MatchProgrammingReportService::class)->sections($data['tournament'], $rounds, $category),
            ...app(PdfLetterheadService::class)->forUser($data['user']),
        ])->render();
    }

    public function test_it_downloads_for_one_several_or_all_fechas_and_for_one_category(): void
    {
        $data = $this->makeTournament();
        $this->actingAs($data['user']);

        foreach (['5', '5,6', 'all'] as $rounds) {
            $response = $this->get(route('tournaments.programming.pdf', [$data['tournament'], 'rounds' => $rounds]));
            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('content-type'));
        }

        $this->get(route('tournaments.programming.pdf', [$data['tournament'], 'rounds' => '5', 'category' => $data['sub13']->id]))->assertOk();
    }

    public function test_it_lists_only_pending_league_matches_split_by_category_venue_and_day(): void
    {
        $data = $this->makeTournament();

        $html = $this->render($data, [5]);

        $this->assertStringContainsString('Quinta fecha', $html);
        $this->assertStringContainsString('Categoría: SUB-13', $html);
        $this->assertStringContainsString('Categoría: SUB-15', $html);
        $this->assertStringContainsString('Lugar: CANCHA PARQUE BOSCÁN', $html);
        $this->assertStringContainsString('Lugar: CANCHA LOS ÍDOLOS', $html);
        $this->assertStringContainsString('sábado 12 de septiembre de 2026', $html);
        $this->assertStringContainsString('domingo 13 de septiembre de 2026', $html);
        $this->assertStringContainsString('7:30 AM', $html);
        $this->assertStringContainsString('GRUPO A', $html);
        $this->assertStringContainsString('Único', $html);
        $this->assertStringContainsString('PUMAS', $html);
        $this->assertStringNotContainsString('JUGADO', $html);
        $this->assertStringNotContainsString('OTRA', $html);
        $this->assertStringNotContainsString('CUARTOS', $html);

        // Youngest category first; inside it, venues alphabetically and the
        // undated match last.
        $this->assertTrue(strpos($html, 'Categoría: SUB-13') < strpos($html, 'Categoría: SUB-15'));
        $this->assertTrue(strpos($html, 'OSOS') < strpos($html, 'TIGRES'));
        $this->assertTrue(strpos($html, 'TIGRES') < strpos($html, 'PUMAS'));
        $this->assertTrue(strpos($html, 'PUMAS') < strpos($html, 'PANTERAS'));
        // The match with no venue gets no "Lugar" line of its own.
        $this->assertSame(2, substr_count($html, 'Lugar:'));
    }

    public function test_several_fechas_each_get_their_own_section(): void
    {
        $data = $this->makeTournament();

        $html = $this->render($data, [5, 6]);

        $this->assertStringContainsString('Quinta fecha', $html);
        $this->assertStringContainsString('Sexta fecha', $html);
        $this->assertTrue(strpos($html, 'Quinta fecha') < strpos($html, 'Sexta fecha'));
        $this->assertSame($html, $this->render($data, null));
    }

    public function test_a_single_category_sheet_leaves_the_others_out(): void
    {
        $data = $this->makeTournament();

        $html = $this->render($data, [5], $data['sub13']);

        $this->assertStringContainsString('TIGRES', $html);
        $this->assertStringNotContainsString('PANTERAS', $html);
    }

    public function test_pending_rounds_only_counts_fechas_with_pending_league_matches(): void
    {
        $data = $this->makeTournament();
        $programming = app(MatchProgrammingReportService::class);

        $this->assertSame([5, 6], $programming->pendingRounds($data['tournament']));
        $this->assertSame([5], $programming->pendingRounds($data['tournament'], $data['sub15']));
    }

    public function test_invalid_or_empty_selections_are_rejected(): void
    {
        $data = $this->makeTournament();
        $this->actingAs($data['user']);

        $this->get(route('tournaments.programming.pdf', [$data['tournament'], 'rounds' => '9']))->assertNotFound();
        $this->get(route('tournaments.programming.pdf', [$data['tournament'], 'rounds' => 'x,1']))->assertRedirect();
        $this->get(route('tournaments.programming.pdf', $data['tournament']))->assertRedirect();
    }

    public function test_a_category_from_another_tournament_is_a_404(): void
    {
        $data = $this->makeTournament();
        $foreign = Category::factory()->for(Tournament::factory()->for($data['user']))->create();

        $this->actingAs($data['user'])
            ->get(route('tournaments.programming.pdf', [$data['tournament'], 'rounds' => '5', 'category' => $foreign->id]))
            ->assertNotFound();
    }

    public function test_other_organizers_cannot_export_it(): void
    {
        $data = $this->makeTournament();

        $this->actingAs(User::factory()->create())
            ->get(route('tournaments.programming.pdf', [$data['tournament'], 'rounds' => 'all']))
            ->assertForbidden();
    }

    public function test_the_tournament_page_offers_the_fecha_picker(): void
    {
        $data = $this->makeTournament();

        $this->actingAs($data['user'])
            ->get(route('tournaments.show', $data['tournament']))
            ->assertOk()
            ->assertSee('Exportar programación')
            ->assertSee('Todas las fechas')
            ->assertSee('Fecha 5')
            ->assertSee('Fecha 6');
    }

    public function test_a_day_without_a_kickoff_time_shows_hora_por_definir(): void
    {
        $data = $this->makeTournament();

        TournamentMatch::query()->where('round_number', 5)->whereNotNull('scheduled_at')->update(['scheduled_at' => '2026-09-12 00:00:00']);

        $html = $this->render($data, [5]);

        $this->assertStringContainsString('sábado 12 de septiembre de 2026', $html);
        $this->assertStringContainsString('Por definir', $html);
        $this->assertStringNotContainsString('12:00 AM', $html);
    }
}
