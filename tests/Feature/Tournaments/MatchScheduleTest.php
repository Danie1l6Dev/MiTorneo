<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchStatus;
use App\Models\LeagueSchedule;
use App\Models\Referee;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Tournaments\Concerns\MakesSchedulableMatches;
use Tests\TestCase;

/**
 * Setting / reprogramming when and where ONE match is played. It lives in the
 * match edit page's single "Guardar cambios" form, together with the status and
 * the referee (TournamentMatchController::update()).
 */
class MatchScheduleTest extends TestCase
{
    use MakesSchedulableMatches, RefreshDatabase;

    /**
     * What the page's form posts: the match's current status plus whatever is
     * being changed.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(TournamentMatch $match, array $changes = []): array
    {
        return ['status' => $match->status->value, ...$changes];
    }

    public function test_a_match_can_be_given_a_day_a_time_and_a_venue(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $venue = $this->makeSchedulingVenue($user, 'Cancha Boscán');
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 5);

        $this->actingAs($user)
            ->put(route('matches.update', $match), $this->form($match, ['scheduled_date' => '2026-09-12', 'kickoff_time' => '07:30', 'venue_id' => $venue->id]))
            ->assertSessionHasNoErrors();

        $match->refresh();
        $this->assertSame('2026-09-12 07:30:00', $match->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame($venue->id, $match->venue_id);
        $this->assertTrue($match->hasKickoffTime());
    }

    public function test_saving_stays_on_the_match_page(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 5);

        $this->actingAs($user)
            ->put(route('matches.update', $match), $this->form($match))
            ->assertRedirect(route('matches.edit', $match))
            ->assertSessionHas('status', 'Cambios guardados correctamente.');
    }

    public function test_a_blank_time_is_stored_as_hora_por_definir(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 5);

        $this->actingAs($user)
            ->put(route('matches.update', $match), $this->form($match, ['scheduled_date' => '2026-09-12', 'kickoff_time' => '']))
            ->assertSessionHasNoErrors();

        $match->refresh();
        $this->assertSame('2026-09-12', $match->scheduled_at->toDateString());
        $this->assertFalse($match->hasKickoffTime());
    }

    public function test_a_clash_is_rejected_and_lists_every_reason(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $venue = $this->makeSchedulingVenue($user, 'Cancha Boscán');
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 4, '2026-09-12 07:30:00', $venue);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Osos', 5);

        $this->actingAs($user)
            ->put(route('matches.update', $match), $this->form($match, ['scheduled_date' => '2026-09-12', 'kickoff_time' => '07:45', 'venue_id' => $venue->id]))
            ->assertSessionHasErrors('schedule');

        $this->assertCount(2, session('errors')->get('schedule'));
        $this->assertNull($match->fresh()->scheduled_at);
    }

    public function test_a_clash_blocks_the_whole_save_including_the_status(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 4, '2026-09-12 07:30:00');
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Osos', 5);

        $this->actingAs($user)
            ->put(route('matches.update', $match), ['status' => MatchStatus::Cancelled->value, 'scheduled_date' => '2026-09-12'])
            ->assertSessionHasErrors('schedule');

        $this->assertSame(MatchStatus::Scheduled, $match->fresh()->status);
    }

    public function test_the_edit_page_shows_the_clashes_that_blocked_a_save(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 4, '2026-09-12 07:30:00');
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Osos', 5);

        $this->actingAs($user)
            ->from(route('matches.edit', $match))
            ->put(route('matches.update', $match), $this->form($match, ['scheduled_date' => '2026-09-12']))
            ->assertRedirect(route('matches.edit', $match).'#programacion');

        $this->actingAs($user)
            ->get(route('matches.edit', $match))
            ->assertOk()
            ->assertSee('No se puede guardar esta programación')
            ->assertSee('TIGRES ya juega ese día');
    }

    public function test_a_time_without_a_day_is_rejected(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 5);

        $this->actingAs($user)
            ->put(route('matches.update', $match), $this->form($match, ['scheduled_date' => '', 'kickoff_time' => '07:30']))
            ->assertSessionHasErrors('scheduled_date');
    }

    public function test_clearing_the_day_of_a_dated_match_postpones_it(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 5, '2026-09-12 07:30:00');

        $this->actingAs($user)
            ->put(route('matches.update', $match), $this->form($match, ['scheduled_date' => '', 'kickoff_time' => '']))
            ->assertSessionHasNoErrors();

        $match->refresh();
        $this->assertNull($match->scheduled_at);
        $this->assertSame(MatchStatus::Postponed, $match->status);
    }

    public function test_saving_without_a_day_on_a_never_dated_match_does_not_postpone_it(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 5);

        $this->actingAs($user)->put(route('matches.update', $match), $this->form($match, ['scheduled_date' => '']));

        $this->assertSame(MatchStatus::Scheduled, $match->fresh()->status);
    }

    public function test_giving_a_postponed_match_a_new_day_brings_it_back_to_scheduled(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 5, status: MatchStatus::Postponed);

        $this->actingAs($user)->put(route('matches.update', $match), $this->form($match, ['scheduled_date' => '2026-09-26', 'kickoff_time' => '09:00']));

        $this->assertSame(MatchStatus::Scheduled, $match->fresh()->status);
    }

    public function test_a_status_picked_on_purpose_wins_over_the_automatic_one(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 5, '2026-09-12 07:30:00');

        $this->actingAs($user)->put(route('matches.update', $match), [
            'status' => MatchStatus::Cancelled->value,
            'scheduled_date' => '',
        ]);

        $match->refresh();
        $this->assertNull($match->scheduled_at);
        $this->assertSame(MatchStatus::Cancelled, $match->status);
    }

    public function test_a_form_that_does_not_carry_the_schedule_leaves_it_alone(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $venue = $this->makeSchedulingVenue($user, 'Cancha Boscán');
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 5, '2026-09-12 07:30:00', $venue);

        $this->actingAs($user)->put(route('matches.update', $match), ['status' => MatchStatus::Scheduled->value, 'scheduled_at' => '2027-01-01T10:00']);

        $match->refresh();
        $this->assertSame('2026-09-12 07:30:00', $match->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame($venue->id, $match->venue_id);
    }

    public function test_a_save_that_does_not_touch_the_schedule_is_not_blocked_by_an_old_clash(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        // Two matches of the same team on one day that already exist (e.g. from
        // before clashes were checked) must not stop an unrelated edit.
        $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 4, '2026-09-12 07:30:00');
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Osos', 5, '2026-09-12 09:00:00');

        $this->actingAs($user)
            ->put(route('matches.update', $match), $this->form($match, [
                'scheduled_date' => '2026-09-12', 'kickoff_time' => '09:00', 'venue_id' => '',
            ]))
            ->assertSessionHasNoErrors();
    }

    public function test_a_venue_of_another_organizer_is_rejected(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $foreign = Venue::factory()->create();
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 5);

        $this->actingAs($user)
            ->put(route('matches.update', $match), $this->form($match, ['scheduled_date' => '2026-09-12', 'venue_id' => $foreign->id]))
            ->assertSessionHasErrors('venue_id');
    }

    public function test_another_organizer_gets_a_403_before_any_clash_is_revealed(): void
    {
        ['tournament' => $tournament] = $this->makeSchedulingTournament();
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 4, '2026-09-12 07:30:00');
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Osos', 5);

        $this->actingAs(User::factory()->create())
            ->put(route('matches.update', $match), $this->form($match, ['scheduled_date' => '2026-09-12']))
            ->assertForbidden();

        $this->assertNull($match->fresh()->scheduled_at);
    }

    public function test_a_match_locked_by_an_expulsion_cannot_be_reprogrammed(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 5);
        $match->forceFill(['is_walkover' => true, 'walkover_team_id' => $match->home_team_id])->save();

        $this->actingAs($user)
            ->put(route('matches.update', $match), $this->form($match, ['scheduled_date' => '2026-09-12']))
            ->assertSessionHas('error');

        $this->assertNull($match->fresh()->scheduled_at);
    }

    public function test_the_edit_page_has_a_single_save_button_with_the_programming_inside(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $venue = $this->makeSchedulingVenue($user, 'Cancha Boscán');
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 5, '2026-09-12 07:30:00', $venue);

        $html = $this->actingAs($user)->get(route('matches.edit', $match))->assertOk()->getContent();

        $this->assertStringContainsString('name="scheduled_date"', $html);
        $this->assertStringContainsString('name="kickoff_time"', $html);
        $this->assertStringContainsString('name="venue_id"', $html);
        $this->assertSame(1, substr_count($html, 'Guardar cambios'));
        $this->assertStringNotContainsString('Guardar programación', $html);
        $this->assertStringNotContainsString('Reprogramar', $html);
        $this->assertStringNotContainsString('matches/'.$match->id.'/schedule', $html);
    }

    public function test_the_edit_page_summarizes_the_current_programming_up_top(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $venue = $this->makeSchedulingVenue($user, 'Cancha Boscán');
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 5, '2026-09-12 07:30:00', $venue);

        $this->actingAs($user)
            ->get(route('matches.edit', $match))
            ->assertOk()
            ->assertSee('href="#programacion"', false)
            ->assertSee('7:30 AM')
            ->assertSee('CANCHA BOSCÁN');
    }

    public function test_assigning_a_referee_who_is_already_directing_at_that_time_is_rejected(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $referee = Referee::factory()->for($user)->create(['full_name' => 'Carlos Gómez']);
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 5, '2026-09-12 07:30:00', referee: $referee);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Osos', 'Lobos', 5, '2026-09-12 08:00:00');

        $this->actingAs($user)
            ->put(route('matches.update', $match), $this->form($match, ['referee_id' => $referee->id]))
            ->assertSessionHasErrors('schedule');

        $this->assertNull($match->fresh()->referee_id);
    }

    public function test_the_referee_and_the_time_are_checked_together_in_one_save(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $referee = Referee::factory()->for($user)->create();
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 5, '2026-09-12 07:30:00', referee: $referee);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Osos', 'Lobos', 5);

        // The match has no time yet: the clash only exists because BOTH the new
        // time and the referee arrive in the same request.
        $this->actingAs($user)
            ->put(route('matches.update', $match), $this->form($match, ['scheduled_date' => '2026-09-12', 'kickoff_time' => '08:00', 'referee_id' => $referee->id]))
            ->assertSessionHasErrors('schedule');

        $this->actingAs($user)
            ->put(route('matches.update', $match), $this->form($match, ['scheduled_date' => '2026-09-12', 'kickoff_time' => '09:30', 'referee_id' => $referee->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($referee->id, $match->fresh()->referee_id);
    }

    public function test_a_referee_can_be_assigned_when_the_times_do_not_overlap(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $referee = Referee::factory()->for($user)->create();
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 5, '2026-09-12 07:30:00', referee: $referee);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Osos', 'Lobos', 5, '2026-09-12 09:30:00');

        $this->actingAs($user)
            ->put(route('matches.update', $match), $this->form($match, ['referee_id' => $referee->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($referee->id, $match->fresh()->referee_id);
    }

    public function test_calendar_cards_show_the_day_time_and_venue_or_sin_programar(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $venue = $this->makeSchedulingVenue($user, 'Cancha Boscán');
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $scheduled = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 1, '2026-09-12 07:30:00', $venue);
        $undated = $this->makeSchedulingMatch($tournament, $category, 'Osos', 'Lobos', 1);
        $noTime = $this->makeSchedulingMatch($tournament, $category, 'Pumas', 'Cóndores', 1, '2026-09-13 00:00:00');

        $phase = $scheduled->competitionPhase;
        $schedule = LeagueSchedule::factory()->for($phase, 'competitionPhase')->for($tournament)->create();
        foreach ([$scheduled, $undated, $noTime] as $match) {
            $match->forceFill(['league_schedule_id' => $schedule->id])->save();
        }

        $this->actingAs($user)
            ->get(route('phases.show', $phase))
            ->assertOk()
            ->assertSee('sáb. 12 sep.')
            ->assertSee('7:30 AM')
            ->assertSee('CANCHA BOSCÁN')
            ->assertSee('Sin programar')
            ->assertSee('Hora por definir');
    }
}
