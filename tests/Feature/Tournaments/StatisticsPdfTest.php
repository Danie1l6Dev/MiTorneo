<?php

namespace Tests\Feature\Tournaments;

use App\Enums\CompetitionPhaseType;
use App\Enums\MatchEventType;
use App\Enums\MatchStatus;
use App\Models\Category;
use App\Models\Coach;
use App\Models\CompetitionPhase;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\CompetitionStatisticsService;
use App\Services\PdfLetterheadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Statistics PDF: per-phase columns plus a total, for one category in one
 * tournament, counting only finished matches.
 */
class StatisticsPdfTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, tournament: Tournament, category: Category, league: CompetitionPhase, knockout: CompetitionPhase, home: Team, away: Team, scorer: Player, other: Player, coach: Coach}
     */
    private function makeData(): array
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create(['name' => 'Copa Uribia']);
        $category = Category::factory()->for($tournament)->create(['name' => 'Sub-17']);

        $league = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::League, 'name' => 'Liga', 'order' => 1]);
        $knockout = CompetitionPhase::factory()->for($tournament)->for($category)->create(['type' => CompetitionPhaseType::Knockout, 'name' => 'Eliminación', 'order' => 2]);

        $home = Team::factory()->for($tournament)->for($category)->create(['name' => 'Tigres']);
        $away = Team::factory()->for($tournament)->for($category)->create(['name' => 'Leones']);
        $scorer = Player::factory()->for($home)->create(['full_name' => 'Ana Pérez']);
        $other = Player::factory()->for($away)->create(['full_name' => 'Beto Ruiz']);
        $coach = Coach::factory()->for($home)->create(['full_name' => 'Carlos DT']);

        $leagueMatch = $this->match($league, $home, $away, MatchStatus::Finished);
        $knockoutMatch = $this->match($knockout, $home, $away, MatchStatus::Finished);
        $unplayed = $this->match($league, $home, $away, MatchStatus::Scheduled);

        $this->event($leagueMatch, $scorer, MatchEventType::Goal, 2);
        $this->event($knockoutMatch, $scorer, MatchEventType::Goal, 1);
        $this->event($leagueMatch, $other, MatchEventType::Goal, 1);
        $this->event($unplayed, $other, MatchEventType::Goal, 5);
        $this->event($knockoutMatch, $other, MatchEventType::YellowCard, 1);
        MatchEvent::factory()->create(['match_id' => $leagueMatch->id, 'team_id' => $home->id, 'coach_id' => $coach->id, 'player_id' => null, 'type' => MatchEventType::YellowCard]);

        // Same catalog category, another tournament: never counted here.
        $elsewhere = CompetitionPhase::factory()->for(Tournament::factory()->for($user))->for($category)->create(['type' => CompetitionPhaseType::League]);
        $this->event($this->match($elsewhere, $home, $away, MatchStatus::Finished), $scorer, MatchEventType::Goal, 4);

        return compact('user', 'tournament', 'category', 'league', 'knockout', 'home', 'away', 'scorer', 'other', 'coach');
    }

    private function match(CompetitionPhase $phase, Team $home, Team $away, MatchStatus $status): TournamentMatch
    {
        return TournamentMatch::factory()->for($phase)->create([
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => $status,
        ]);
    }

    private function event(TournamentMatch $match, Player $player, MatchEventType $type, int $count): void
    {
        MatchEvent::factory()->count($count)->create([
            'match_id' => $match->id,
            'team_id' => $player->team_id,
            'player_id' => $player->id,
            'type' => $type,
        ]);
    }

    public function test_goals_are_split_by_phase_with_a_total(): void
    {
        $data = $this->makeData();

        $result = app(CompetitionStatisticsService::class)->phaseBreakdown($data['tournament'], $data['category'], MatchEventType::Goal);

        $this->assertSame(['LIGA', 'ELIMINACIÓN'], $result['phases']->pluck('name')->all());
        $this->assertCount(2, $result['rows']);

        [$first, $second] = $result['rows'];
        $this->assertSame('ANA PÉREZ', $first['name']);
        $this->assertSame([$data['league']->id => 2, $data['knockout']->id => 1], $first['counts']);
        $this->assertSame(3, $first['total']);
        // The unplayed match's 5 goals and the other tournament's 4 don't count.
        $this->assertSame('BETO RUIZ', $second['name']);
        $this->assertSame(1, $second['total']);
    }

    public function test_card_tables_also_list_the_coaches(): void
    {
        $data = $this->makeData();

        $rows = app(CompetitionStatisticsService::class)->phaseBreakdown($data['tournament'], $data['category'], MatchEventType::YellowCard)['rows'];

        $this->assertEqualsCanonicalizing(['BETO RUIZ', 'DT: CARLOS DT'], array_column($rows, 'name'));
    }

    public function test_the_pdf_renders_phase_columns_and_the_total(): void
    {
        $data = $this->makeData();
        $statistics = app(CompetitionStatisticsService::class);

        $html = view('pdf.statistics', [
            'tournament' => $data['tournament'],
            'category' => $data['category'],
            'tables' => [
                ['type' => MatchEventType::Goal, ...$statistics->phaseBreakdown($data['tournament'], $data['category'], MatchEventType::Goal)],
                ['type' => MatchEventType::RedCard, ...$statistics->phaseBreakdown($data['tournament'], $data['category'], MatchEventType::RedCard)],
            ],
            ...app(PdfLetterheadService::class)->forUser($data['user']),
        ])->render();

        $this->assertStringContainsString('Goleadores', $html);
        $this->assertStringContainsString('>LIGA</th>', $html);
        $this->assertStringContainsString('>ELIMINACIÓN</th>', $html);
        $this->assertStringContainsString('>Total</th>', $html);
        $this->assertStringContainsString('Todavía no hay registros de rojas', $html);
    }

    public function test_every_type_and_all_download_as_pdf(): void
    {
        $data = $this->makeData();
        $this->actingAs($data['user']);

        foreach (['goal', 'assist', 'yellow_card', 'red_card', 'all'] as $type) {
            $response = $this->get(route('tournaments.categories.statistics.pdf', [$data['tournament'], $data['category'], 'type' => $type]));
            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('content-type'), $type);
        }
    }

    public function test_invalid_type_foreign_category_and_other_organizers_are_rejected(): void
    {
        $data = $this->makeData();

        $this->actingAs($data['user'])
            ->get(route('tournaments.categories.statistics.pdf', [$data['tournament'], $data['category'], 'type' => 'minutes']))
            ->assertSessionHasErrors('type');

        $this->actingAs($data['user'])
            ->get(route('tournaments.categories.statistics.pdf', [$data['tournament'], Category::factory()->for($data['tournament'])->create(), 'type' => 'goal']))
            ->assertNotFound();

        $this->actingAs(User::factory()->create())
            ->get(route('tournaments.categories.statistics.pdf', [$data['tournament'], $data['category'], 'type' => 'goal']))
            ->assertForbidden();
    }

    public function test_the_statistics_tabs_offer_the_export(): void
    {
        $data = $this->makeData();

        $this->actingAs($data['user'])
            ->get(route('phases.show', $data['league']))
            ->assertOk()
            ->assertSee('Todas las estadísticas');
    }
}
