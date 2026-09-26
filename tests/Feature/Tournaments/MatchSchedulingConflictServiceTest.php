<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchStatus;
use App\Models\Referee;
use App\Services\MatchSchedulingConflictService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Tournaments\Concerns\MakesSchedulableMatches;
use Tests\TestCase;

class MatchSchedulingConflictServiceTest extends TestCase
{
    use MakesSchedulableMatches, RefreshDatabase;

    private MatchSchedulingConflictService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MatchSchedulingConflictService;
    }

    public function test_a_team_cannot_play_twice_on_the_same_day(): void
    {
        ['tournament' => $tournament] = $this->makeSchedulingTournament();
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 1, '2026-09-12 07:30:00');
        $second = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Osos', 2);

        $conflicts = $this->service->conflictsFor($second, Carbon::parse('2026-09-12 15:00:00'), null);

        $this->assertCount(1, $conflicts);
        $this->assertStringContainsString('TIGRES ya juega ese día', $conflicts[0]);
        $this->assertStringContainsString('LEONES', $conflicts[0]);
    }

    public function test_the_message_uses_the_plural_when_both_teams_already_play_that_day(): void
    {
        ['tournament' => $tournament] = $this->makeSchedulingTournament();
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 1, '2026-09-12 07:30:00');
        $return = $this->makeSchedulingMatch($tournament, $category, 'Leones', 'Tigres', 2);

        $conflicts = $this->service->conflictsFor($return, Carbon::parse('2026-09-12 15:00:00'), null);

        $this->assertCount(1, $conflicts);
        $this->assertStringContainsString('ya juegan ese día', $conflicts[0]);
    }

    public function test_the_same_team_on_another_day_is_fine(): void
    {
        ['tournament' => $tournament] = $this->makeSchedulingTournament();
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 1, '2026-09-12 07:30:00');
        $second = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Osos', 2);

        $this->assertSame([], $this->service->conflictsFor($second, Carbon::parse('2026-09-19 07:30:00'), null));
    }

    public function test_a_day_without_a_kickoff_time_still_blocks_the_team(): void
    {
        ['tournament' => $tournament] = $this->makeSchedulingTournament();
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 1, '2026-09-12 00:00:00');
        $second = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Osos', 2);

        $this->assertCount(1, $this->service->conflictsFor($second, Carbon::parse('2026-09-12 00:00:00'), null));
    }

    public function test_a_venue_cannot_host_two_overlapping_matches(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $venue = $this->makeSchedulingVenue($user, 'Cancha Boscán');
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013, duration: 60);
        $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 1, '2026-09-12 07:30:00', $venue);
        $other = $this->makeSchedulingMatch($tournament, $category, 'Osos', 'Lobos', 1);

        $conflicts = $this->service->conflictsFor($other, Carbon::parse('2026-09-12 08:15:00'), $venue->id);

        $this->assertCount(1, $conflicts);
        $this->assertStringContainsString('CANCHA BOSCÁN está ocupada de 7:30 AM a 8:30 AM', $conflicts[0]);
    }

    public function test_back_to_back_matches_on_a_venue_do_not_overlap(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $venue = $this->makeSchedulingVenue($user, 'Cancha Boscán');
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013, duration: 60);
        $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 1, '2026-09-12 07:30:00', $venue);
        $other = $this->makeSchedulingMatch($tournament, $category, 'Osos', 'Lobos', 1);

        $this->assertSame([], $this->service->conflictsFor($other, Carbon::parse('2026-09-12 08:30:00'), $venue->id));
    }

    public function test_the_duration_used_is_the_one_of_each_matchs_own_category(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $venue = $this->makeSchedulingVenue($user, 'Cancha Boscán');
        $baby = $this->makeSchedulingCategory($tournament, 'Baby', 2020, duration: 20);
        $juvenil = $this->makeSchedulingCategory($tournament, 'Juvenil', 2008);
        $this->makeSchedulingMatch($tournament, $baby, 'Tigres', 'Leones', 1, '2026-09-12 07:30:00', $venue);
        $later = $this->makeSchedulingMatch($tournament, $juvenil, 'Osos', 'Lobos', 1);

        // Baby is over at 7:50, so 7:50 is free; 7:45 would still overlap it.
        $this->assertSame([], $this->service->conflictsFor($later, Carbon::parse('2026-09-12 07:50:00'), $venue->id));
        $this->assertCount(1, $this->service->conflictsFor($later, Carbon::parse('2026-09-12 07:45:00'), $venue->id));
    }

    public function test_different_venues_or_no_venue_never_clash_on_a_venue(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $boscan = $this->makeSchedulingVenue($user, 'Cancha Boscán');
        $idolos = $this->makeSchedulingVenue($user, 'Cancha Los Ídolos');
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 1, '2026-09-12 07:30:00', $boscan);
        $other = $this->makeSchedulingMatch($tournament, $category, 'Osos', 'Lobos', 1);

        $this->assertSame([], $this->service->conflictsFor($other, Carbon::parse('2026-09-12 07:30:00'), $idolos->id));
        $this->assertSame([], $this->service->conflictsFor($other, Carbon::parse('2026-09-12 07:30:00'), null));
    }

    public function test_a_day_without_a_kickoff_time_cannot_overlap_on_a_venue(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $venue = $this->makeSchedulingVenue($user, 'Cancha Boscán');
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 1, '2026-09-12 07:30:00', $venue);
        $other = $this->makeSchedulingMatch($tournament, $category, 'Osos', 'Lobos', 1);

        $this->assertSame([], $this->service->conflictsFor($other, Carbon::parse('2026-09-12 00:00:00'), $venue->id));
    }

    public function test_a_referee_cannot_direct_two_overlapping_matches_even_on_different_venues(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $referee = Referee::factory()->for($user)->create(['full_name' => 'Carlos Gómez']);
        $boscan = $this->makeSchedulingVenue($user, 'Cancha Boscán');
        $idolos = $this->makeSchedulingVenue($user, 'Cancha Los Ídolos');
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 1, '2026-09-12 07:30:00', $boscan, referee: $referee);
        $other = $this->makeSchedulingMatch($tournament, $category, 'Osos', 'Lobos', 1, referee: $referee);

        $conflicts = $this->service->conflictsFor($other, Carbon::parse('2026-09-12 08:00:00'), $idolos->id);

        $this->assertCount(1, $conflicts);
        $this->assertStringContainsString('CARLOS GÓMEZ ya dirige', $conflicts[0]);
    }

    public function test_two_teams_of_the_same_club_playing_at_once_is_not_a_conflict(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $boscan = $this->makeSchedulingVenue($user, 'Cancha Boscán');
        $idolos = $this->makeSchedulingVenue($user, 'Cancha Los Ídolos');
        $pony = $this->makeSchedulingCategory($tournament, 'Pony', 2014);
        $juvenil = $this->makeSchedulingCategory($tournament, 'Juvenil', 2008);
        $this->makeSchedulingMatch($tournament, $pony, 'Unión Maicao', 'Jair Pinto', 5, '2026-09-13 07:30:00', $boscan);
        $other = $this->makeSchedulingMatch($tournament, $juvenil, 'Unión Maicao', 'Mareygua', 5);

        // Different Team rows (one per category), so nothing is shared.
        $this->assertSame([], $this->service->conflictsFor($other, Carbon::parse('2026-09-13 07:30:00'), $idolos->id));
    }

    public function test_a_match_never_conflicts_with_its_own_current_slot(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $venue = $this->makeSchedulingVenue($user, 'Cancha Boscán');
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 1, '2026-09-12 07:30:00', $venue);

        $this->assertSame([], $this->service->conflictsFor($match, Carbon::parse('2026-09-12 07:45:00'), $venue->id));
    }

    public function test_a_postponed_match_holds_no_slot(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $venue = $this->makeSchedulingVenue($user, 'Cancha Boscán');
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 1, '2026-09-12 07:30:00', $venue, MatchStatus::Postponed);
        $other = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Osos', 2);

        $this->assertSame([], $this->service->conflictsFor($other, Carbon::parse('2026-09-12 07:30:00'), $venue->id));
    }

    public function test_a_batch_is_checked_against_itself(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $venue = $this->makeSchedulingVenue($user, 'Cancha Boscán');
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $a = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 1);
        $b = $this->makeSchedulingMatch($tournament, $category, 'Osos', 'Lobos', 1);

        $conflicts = $this->service->conflictsForBatch(collect([$a, $b]), [
            $a->id => ['at' => Carbon::parse('2026-09-12 07:30:00'), 'venue_id' => $venue->id],
            $b->id => ['at' => Carbon::parse('2026-09-12 07:45:00'), 'venue_id' => $venue->id],
        ]);

        $this->assertArrayHasKey($a->id, $conflicts);
        $this->assertArrayHasKey($b->id, $conflicts);
    }

    public function test_clearing_the_day_has_nothing_to_check(): void
    {
        ['tournament' => $tournament] = $this->makeSchedulingTournament();
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 1, '2026-09-12 07:30:00');

        $this->assertSame([], $this->service->conflictsFor($match, null, null));
    }
}
