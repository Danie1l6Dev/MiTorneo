<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchEventType;
use App\Enums\RosterEndReason;
use App\Enums\RosterStartReason;
use App\Models\Category;
use App\Models\Club;
use App\Models\CompetitionPhase;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\PlayerTeamHistory;
use App\Models\Sanction;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\PlayerHistoryBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Building the history players had before it was recorded, and checking the
 * recorded history against the real roster. Insert-only and repeatable.
 */
class PlayerHistoryBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A player who is on Nilmar (primary) and on a second plantel of it (pivot),
     * registered long before history existed, and who once scored and got a red
     * card while at another club -- Halcones -- whose plantel he's no longer on.
     *
     * @return array{user: User, player: Player, primary: Team, extra: Team, past: Team}
     */
    private function playerWithAPast(): array
    {
        Carbon::setTestNow('2026-02-01 09:00:00');

        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create();
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();

        $nilmar = Club::factory()->for($user)->create(['name' => 'Nilmar']);
        $halcones = Club::factory()->for($user)->create(['name' => 'Halcones']);
        $primary = Team::factory()->for($tournament)->for($category)->create(['club_id' => $nilmar->id, 'name' => 'Nilmar']);
        $extra = Team::factory()->for($tournament)->for($category)->create(['club_id' => $nilmar->id, 'name' => 'Nilmar B']);
        $past = Team::factory()->for($tournament)->for($category)->create(['club_id' => $halcones->id, 'name' => 'Halcones']);
        $rival = Team::factory()->for($tournament)->for($category)->create();

        $player = Player::factory()->create(['team_id' => $primary->id, 'jersey_number' => 10]);

        Carbon::setTestNow('2026-03-15 09:00:00');
        $player->teams()->attach($extra->id, ['jersey_number' => 5]);

        $early = TournamentMatch::factory()->for($phase)->create(['home_team_id' => $past->id, 'away_team_id' => $rival->id, 'scheduled_at' => '2025-08-10 10:00:00']);
        $late = TournamentMatch::factory()->for($phase)->create(['home_team_id' => $past->id, 'away_team_id' => $rival->id, 'scheduled_at' => '2025-11-20 10:00:00']);
        MatchEvent::factory()->create(['match_id' => $early->id, 'team_id' => $past->id, 'player_id' => $player->id, 'type' => MatchEventType::Goal]);
        $red = MatchEvent::factory()->create(['match_id' => $late->id, 'team_id' => $past->id, 'player_id' => $player->id, 'type' => MatchEventType::RedCard]);
        Sanction::factory()->create(['match_id' => $late->id, 'match_event_id' => $red->id, 'team_id' => $past->id, 'player_id' => $player->id]);

        Carbon::setTestNow();

        return compact('user', 'player', 'primary', 'extra', 'past');
    }

    public function test_current_planteles_get_an_open_estimated_line_dated_from_when_they_were_linked(): void
    {
        $data = $this->playerWithAPast();

        app(PlayerHistoryBackfillService::class)->run();

        $primary = $data['player']->teamHistory()->where('team_id', $data['primary']->id)->firstOrFail();
        $extra = $data['player']->teamHistory()->where('team_id', $data['extra']->id)->firstOrFail();

        $this->assertTrue($primary->isOpen());
        $this->assertTrue($primary->is_estimated);
        $this->assertSame(RosterStartReason::Estimated, $primary->start_reason);
        $this->assertSame('2026-02-01', $primary->started_on->toDateString());
        $this->assertSame(10, $primary->jersey_number);
        $this->assertSame('NILMAR', $primary->club_name);

        $this->assertSame('2026-03-15', $extra->started_on->toDateString());
        $this->assertSame(5, $extra->jersey_number);
    }

    public function test_a_past_plantel_seen_in_the_events_and_sanctions_gets_a_closed_line_spanning_them(): void
    {
        $data = $this->playerWithAPast();

        app(PlayerHistoryBackfillService::class)->run();

        $past = $data['player']->teamHistory()->where('team_id', $data['past']->id)->firstOrFail();

        $this->assertFalse($past->isOpen());
        $this->assertTrue($past->is_estimated);
        $this->assertSame('2025-08-10', $past->started_on->toDateString());
        $this->assertSame('2025-11-20', $past->ended_on->toDateString());
        $this->assertSame(RosterEndReason::Unknown, $past->end_reason);
        $this->assertSame('HALCONES', $past->club_name);
    }

    public function test_running_it_again_adds_nothing(): void
    {
        $this->playerWithAPast();

        $first = app(PlayerHistoryBackfillService::class)->run();
        $second = app(PlayerHistoryBackfillService::class)->run();

        $this->assertSame(3, PlayerTeamHistory::query()->count());
        $this->assertSame(2, $first['current']);
        $this->assertSame(1, $first['past']);
        $this->assertSame(0, $second['current'] + $second['past']);
    }

    public function test_a_plantel_that_already_has_a_recorded_line_is_left_alone(): void
    {
        $data = $this->playerWithAPast();
        $real = PlayerTeamHistory::factory()->create([
            'player_id' => $data['player']->id, 'team_id' => $data['past']->id, 'started_on' => '2025-01-01', 'is_estimated' => false,
        ])->fresh();

        app(PlayerHistoryBackfillService::class)->run();

        $this->assertSame(1, $data['player']->teamHistory()->where('team_id', $data['past']->id)->count());
        $this->assertFalse($real->fresh()->is_estimated);
    }

    public function test_a_dry_run_reports_but_writes_nothing(): void
    {
        $this->playerWithAPast();

        $report = app(PlayerHistoryBackfillService::class)->run(dryRun: true);

        $this->assertSame(0, PlayerTeamHistory::query()->count());
        $this->assertSame(2, $report['current']);
        $this->assertSame(1, $report['past']);
        $this->assertCount(3, $report['rows']);
    }

    public function test_it_can_be_limited_to_one_organizer(): void
    {
        $data = $this->playerWithAPast();
        $other = $this->playerWithAPast();

        app(PlayerHistoryBackfillService::class)->run(ownerId: $data['user']->id);

        $this->assertSame(3, $data['player']->teamHistory()->count());
        $this->assertSame(0, $other['player']->teamHistory()->count());
    }

    public function test_the_command_simulates_and_then_creates(): void
    {
        $this->playerWithAPast();

        $this->artisan('players:backfill-history', ['--dry-run' => true])
            ->expectsOutputToContain('SIMULACIÓN')
            ->expectsOutputToContain('Líneas de planteles actuales: 2')
            ->assertSuccessful();
        $this->assertSame(0, PlayerTeamHistory::query()->count());

        $this->artisan('players:backfill-history')->assertSuccessful();
        $this->assertSame(3, PlayerTeamHistory::query()->count());

        $this->artisan('players:backfill-history')->expectsOutputToContain('Nada que crear')->assertSuccessful();
    }

    public function test_the_drift_check_is_clean_after_the_backfill_and_flags_a_disagreement_otherwise(): void
    {
        $data = $this->playerWithAPast();

        // Nothing recorded yet: both current planteles lack their open line.
        $this->assertCount(2, app(PlayerHistoryBackfillService::class)->drift());
        $this->artisan('players:check-history')->assertFailed();

        app(PlayerHistoryBackfillService::class)->run();

        $this->assertSame([], app(PlayerHistoryBackfillService::class)->drift());
        $this->artisan('players:check-history')->expectsOutputToContain('coincide')->assertSuccessful();

        // The player leaves a plantel behind the service's back: an open line with no link.
        $data['player']->teams()->detach($data['extra']->id);

        $problems = app(PlayerHistoryBackfillService::class)->drift();
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('ya no está en él', $problems[0]['problem']);
    }
}
