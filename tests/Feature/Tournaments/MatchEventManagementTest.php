<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchEventType;
use App\Models\Category;
use App\Models\Coach;
use App\Models\CompetitionPhase;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchEventManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: TournamentMatch, 1: Player, 2: Player, 3: Player}
     */
    private function makeMatchWithPlayers(User $user): array
    {
        $tournament = Tournament::factory()->for($user)->create();
        $category = Category::factory()->for($tournament)->create(['uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();
        $home = Team::factory()->for($tournament)->for($category)->create();
        $away = Team::factory()->for($tournament)->for($category)->create();
        $outsider = Team::factory()->for($tournament)->for($category)->create();

        $match = TournamentMatch::factory()->for($phase)->create([
            'tournament_id' => $tournament->id,
            'category_id' => $category->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
        ]);

        $homePlayer = Player::factory()->for($home)->create(['jersey_number' => 9]);
        $awayPlayer = Player::factory()->for($away)->create(['jersey_number' => 7]);
        $outsiderPlayer = Player::factory()->for($outsider)->create(['jersey_number' => 1]);

        return [$match, $homePlayer, $awayPlayer, $outsiderPlayer];
    }

    /**
     * @return array{0: TournamentMatch, 1: Coach, 2: Coach, 3: Coach}
     */
    private function makeMatchWithCoaches(User $user): array
    {
        [$match] = $this->makeMatchWithPlayers($user);

        $homeCoach = Coach::factory()->for($match->homeTeam)->create();
        $awayCoach = Coach::factory()->for($match->awayTeam)->create();

        $outsiderTeam = Team::factory()->for($match->tournament)->for($match->category)->create();
        $outsiderCoach = Coach::factory()->for($outsiderTeam)->create();

        return [$match, $homeCoach, $awayCoach, $outsiderCoach];
    }

    // ── Goles ────────────────────────────────────────────────────────────

    public function test_a_user_can_register_a_goal_for_a_player_of_a_participating_team(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'goal',
            'player_id' => $homePlayer->id,
            'minute' => 12,
        ])->assertRedirect(route('matches.edit', $match));

        $this->assertDatabaseHas('match_events', [
            'match_id' => $match->id,
            'team_id' => $homePlayer->team_id,
            'player_id' => $homePlayer->id,
            'type' => 'goal',
            'minute' => 12,
        ]);
    }

    public function test_a_goal_can_be_registered_without_a_minute(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'goal',
            'player_id' => $homePlayer->id,
        ])->assertRedirect(route('matches.edit', $match));

        $this->assertDatabaseHas('match_events', [
            'match_id' => $match->id,
            'player_id' => $homePlayer->id,
            'type' => 'goal',
            'minute' => null,
        ]);
    }

    public function test_a_goal_cannot_be_registered_for_a_player_of_a_non_participating_team(): void
    {
        $user = User::factory()->create();
        [$match, , , $outsiderPlayer] = $this->makeMatchWithPlayers($user);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'goal',
            'player_id' => $outsiderPlayer->id,
            'minute' => 12,
        ])->assertSessionHasErrors('player_id');

        $this->assertDatabaseMissing('match_events', ['match_id' => $match->id]);
    }

    public function test_a_goal_can_be_deleted(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);

        $event = MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $homePlayer->team_id,
            'player_id' => $homePlayer->id,
            'type' => MatchEventType::Goal,
        ]);

        $this->actingAs($user)->delete(route('events.destroy', $event))
            ->assertRedirect(route('matches.edit', $match));

        $this->assertDatabaseMissing('match_events', ['id' => $event->id]);
    }

    public function test_a_goal_cannot_be_deleted_if_it_would_leave_too_many_assists(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);
        $homeAssister = Player::factory()->for($match->homeTeam)->create(['jersey_number' => 11]);

        $goal = MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $homePlayer->team_id,
            'player_id' => $homePlayer->id,
            'type' => MatchEventType::Goal,
        ]);

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $homePlayer->team_id,
            'player_id' => $homeAssister->id,
            'type' => MatchEventType::Assist,
        ]);

        $this->actingAs($user)->delete(route('events.destroy', $goal))
            ->assertRedirect(route('matches.edit', $match))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('match_events', ['id' => $goal->id]);
    }

    public function test_a_player_can_score_multiple_goals_in_the_same_match(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'goal', 'player_id' => $homePlayer->id, 'minute' => 20,
        ]);
        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'goal', 'player_id' => $homePlayer->id, 'minute' => 55,
        ]);

        $this->assertSame(2, MatchEvent::query()
            ->where('match_id', $match->id)
            ->where('player_id', $homePlayer->id)
            ->where('type', 'goal')
            ->count());
    }

    // ── Asistencias ──────────────────────────────────────────────────────

    public function test_a_user_can_register_an_assist_for_a_player_of_a_participating_team(): void
    {
        $user = User::factory()->create();
        [$match, , $awayPlayer] = $this->makeMatchWithPlayers($user);
        $awayAssister = Player::factory()->for($match->awayTeam)->create(['jersey_number' => 8]);

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $awayPlayer->team_id,
            'player_id' => $awayPlayer->id,
            'type' => MatchEventType::Goal,
        ]);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'assist',
            'player_id' => $awayAssister->id,
            'minute' => 52,
        ])->assertRedirect(route('matches.edit', $match));

        $this->assertDatabaseHas('match_events', [
            'match_id' => $match->id,
            'player_id' => $awayAssister->id,
            'type' => 'assist',
            'minute' => 52,
        ]);
    }

    public function test_an_assist_cannot_be_registered_for_an_outside_player(): void
    {
        $user = User::factory()->create();
        [$match, , , $outsiderPlayer] = $this->makeMatchWithPlayers($user);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'assist',
            'player_id' => $outsiderPlayer->id,
            'minute' => 52,
        ])->assertSessionHasErrors('player_id');
    }

    public function test_an_assist_cannot_be_registered_without_a_matching_goal_for_the_team(): void
    {
        $user = User::factory()->create();
        [$match, , $awayPlayer] = $this->makeMatchWithPlayers($user);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'assist',
            'player_id' => $awayPlayer->id,
        ])->assertSessionHasErrors('type');

        $this->assertDatabaseMissing('match_events', ['player_id' => $awayPlayer->id]);
    }

    public function test_the_only_goal_and_the_only_assist_of_a_team_cannot_belong_to_the_same_player(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $homePlayer->team_id,
            'player_id' => $homePlayer->id,
            'type' => MatchEventType::Goal,
        ]);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'assist',
            'player_id' => $homePlayer->id,
        ])->assertSessionHasErrors('type');

        $this->assertDatabaseMissing('match_events', ['player_id' => $homePlayer->id, 'type' => 'assist']);
    }

    public function test_an_assist_can_be_deleted(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);

        $event = MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $homePlayer->team_id,
            'player_id' => $homePlayer->id,
            'type' => MatchEventType::Assist,
        ]);

        $this->actingAs($user)->delete(route('events.destroy', $event));

        $this->assertDatabaseMissing('match_events', ['id' => $event->id]);
    }

    // ── Tarjetas ─────────────────────────────────────────────────────────

    public function test_a_user_can_register_a_yellow_card(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'yellow_card',
            'player_id' => $homePlayer->id,
            'minute' => 24,
        ])->assertRedirect(route('matches.edit', $match));

        $this->assertDatabaseHas('match_events', [
            'match_id' => $match->id,
            'player_id' => $homePlayer->id,
            'type' => 'yellow_card',
        ]);
    }

    public function test_a_user_can_register_a_red_card(): void
    {
        $user = User::factory()->create();
        [$match, , $awayPlayer] = $this->makeMatchWithPlayers($user);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'red_card',
            'player_id' => $awayPlayer->id,
            'minute' => 68,
        ])->assertRedirect(route('matches.edit', $match));

        $this->assertDatabaseHas('match_events', [
            'match_id' => $match->id,
            'player_id' => $awayPlayer->id,
            'type' => 'red_card',
        ]);
    }

    public function test_a_card_can_be_deleted(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);

        $event = MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $homePlayer->team_id,
            'player_id' => $homePlayer->id,
            'type' => MatchEventType::YellowCard,
        ]);

        $this->actingAs($user)->delete(route('events.destroy', $event));

        $this->assertDatabaseMissing('match_events', ['id' => $event->id]);
    }

    public function test_deleting_the_red_from_a_second_yellow_reverts_it_to_a_single_caution(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);

        MatchEvent::factory()->count(2)->create([
            'match_id' => $match->id,
            'team_id' => $homePlayer->team_id,
            'player_id' => $homePlayer->id,
            'type' => MatchEventType::YellowCard,
        ]);

        $red = MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $homePlayer->team_id,
            'player_id' => $homePlayer->id,
            'type' => MatchEventType::RedCard,
        ]);

        $this->actingAs($user)->delete(route('events.destroy', $red));

        $this->assertSame(1, MatchEvent::query()->where('player_id', $homePlayer->id)->where('type', 'yellow_card')->count());
        $this->assertSame(0, MatchEvent::query()->where('player_id', $homePlayer->id)->where('type', 'red_card')->count());
    }

    public function test_deleting_one_of_a_second_yellows_pair_also_removes_the_paired_red(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);

        $yellows = MatchEvent::factory()->count(2)->create([
            'match_id' => $match->id,
            'team_id' => $homePlayer->team_id,
            'player_id' => $homePlayer->id,
            'type' => MatchEventType::YellowCard,
        ]);

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $homePlayer->team_id,
            'player_id' => $homePlayer->id,
            'type' => MatchEventType::RedCard,
        ]);

        $this->actingAs($user)->delete(route('events.destroy', $yellows->first()));

        $this->assertSame(1, MatchEvent::query()->where('player_id', $homePlayer->id)->where('type', 'yellow_card')->count());
        $this->assertSame(0, MatchEvent::query()->where('player_id', $homePlayer->id)->where('type', 'red_card')->count());
    }

    public function test_deleting_a_lone_yellow_does_not_touch_an_unrelated_straight_red(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);

        $yellow = MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $homePlayer->team_id,
            'player_id' => $homePlayer->id,
            'type' => MatchEventType::YellowCard,
        ]);

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $homePlayer->team_id,
            'player_id' => $homePlayer->id,
            'type' => MatchEventType::RedCard,
        ]);

        $this->actingAs($user)->delete(route('events.destroy', $yellow));

        $this->assertSame(0, MatchEvent::query()->where('player_id', $homePlayer->id)->where('type', 'yellow_card')->count());
        $this->assertSame(1, MatchEvent::query()->where('player_id', $homePlayer->id)->where('type', 'red_card')->count());
    }

    public function test_a_player_cannot_receive_a_third_yellow_card_in_the_same_match(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);

        MatchEvent::factory()->count(2)->create([
            'match_id' => $match->id,
            'team_id' => $homePlayer->team_id,
            'player_id' => $homePlayer->id,
            'type' => MatchEventType::YellowCard,
        ]);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'yellow_card',
            'player_id' => $homePlayer->id,
        ])->assertSessionHasErrors('type');

        $this->assertSame(2, MatchEvent::query()->where('player_id', $homePlayer->id)->where('type', 'yellow_card')->count());
    }

    public function test_a_player_cannot_receive_a_second_red_card_in_the_same_match(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $homePlayer->team_id,
            'player_id' => $homePlayer->id,
            'type' => MatchEventType::RedCard,
        ]);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'red_card',
            'player_id' => $homePlayer->id,
        ])->assertSessionHasErrors('type');

        $this->assertSame(1, MatchEvent::query()->where('player_id', $homePlayer->id)->where('type', 'red_card')->count());
    }

    public function test_a_batch_cannot_register_a_third_yellow_card_for_the_same_player(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);

        MatchEvent::factory()->count(2)->create([
            'match_id' => $match->id,
            'team_id' => $homePlayer->team_id,
            'player_id' => $homePlayer->id,
            'type' => MatchEventType::YellowCard,
        ]);

        $this->actingAs($user)->post(route('matches.events.batch-store', $match), [
            'events' => [
                ['type' => 'yellow_card', 'player_id' => $homePlayer->id],
            ],
        ])->assertSessionHasErrors('events.0.type');

        $this->assertSame(2, MatchEvent::query()->where('player_id', $homePlayer->id)->where('type', 'yellow_card')->count());
    }

    public function test_a_card_cannot_be_registered_for_an_outside_player(): void
    {
        $user = User::factory()->create();
        [$match, , , $outsiderPlayer] = $this->makeMatchWithPlayers($user);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'yellow_card',
            'player_id' => $outsiderPlayer->id,
            'minute' => 24,
        ])->assertSessionHasErrors('player_id');
    }

    // ── Tarjetas al director técnico ─────────────────────────────────────

    public function test_a_user_can_register_a_yellow_card_for_a_coach(): void
    {
        $user = User::factory()->create();
        [$match, $homeCoach] = $this->makeMatchWithCoaches($user);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'yellow_card',
            'coach_id' => $homeCoach->id,
        ])->assertRedirect(route('matches.edit', $match));

        $this->assertDatabaseHas('match_events', [
            'match_id' => $match->id,
            'coach_id' => $homeCoach->id,
            'player_id' => null,
            'team_id' => $homeCoach->team_id,
            'type' => 'yellow_card',
        ]);
    }

    public function test_a_user_can_register_a_red_card_for_a_coach(): void
    {
        $user = User::factory()->create();
        [$match, , $awayCoach] = $this->makeMatchWithCoaches($user);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'red_card',
            'coach_id' => $awayCoach->id,
        ])->assertRedirect(route('matches.edit', $match));

        $this->assertDatabaseHas('match_events', [
            'match_id' => $match->id,
            'coach_id' => $awayCoach->id,
            'type' => 'red_card',
        ]);
    }

    public function test_a_coach_cannot_receive_a_second_red_card_in_the_same_match(): void
    {
        $user = User::factory()->create();
        [$match, $homeCoach] = $this->makeMatchWithCoaches($user);

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $homeCoach->team_id,
            'coach_id' => $homeCoach->id,
            'player_id' => null,
            'type' => MatchEventType::RedCard,
        ]);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'red_card',
            'coach_id' => $homeCoach->id,
        ])->assertSessionHasErrors('type');

        $this->assertSame(1, MatchEvent::query()->where('coach_id', $homeCoach->id)->where('type', 'red_card')->count());
    }

    public function test_a_coach_cannot_be_registered_a_goal(): void
    {
        $user = User::factory()->create();
        [$match, $homeCoach] = $this->makeMatchWithCoaches($user);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'goal',
            'coach_id' => $homeCoach->id,
        ])->assertSessionHasErrors('type');

        $this->assertDatabaseMissing('match_events', ['coach_id' => $homeCoach->id]);
    }

    public function test_a_coach_cannot_be_registered_an_assist(): void
    {
        $user = User::factory()->create();
        [$match, $homeCoach] = $this->makeMatchWithCoaches($user);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'assist',
            'coach_id' => $homeCoach->id,
        ])->assertSessionHasErrors('type');
    }

    public function test_a_card_cannot_be_registered_for_a_coach_of_an_outside_team(): void
    {
        $user = User::factory()->create();
        [$match, , , $outsiderCoach] = $this->makeMatchWithCoaches($user);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'yellow_card',
            'coach_id' => $outsiderCoach->id,
        ])->assertSessionHasErrors('coach_id');
    }

    public function test_an_event_cannot_have_both_a_player_and_a_coach(): void
    {
        $user = User::factory()->create();
        [$match, $homeCoach] = $this->makeMatchWithCoaches($user);
        $homePlayer = Player::factory()->for($match->homeTeam)->create();

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'yellow_card',
            'player_id' => $homePlayer->id,
            'coach_id' => $homeCoach->id,
        ])->assertSessionHasErrors('player_id');
    }

    public function test_a_coachs_card_can_be_deleted(): void
    {
        $user = User::factory()->create();
        [$match, $homeCoach] = $this->makeMatchWithCoaches($user);

        $event = MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $homeCoach->team_id,
            'coach_id' => $homeCoach->id,
            'player_id' => null,
            'type' => MatchEventType::YellowCard,
        ]);

        $this->actingAs($user)->delete(route('events.destroy', $event));

        $this->assertDatabaseMissing('match_events', ['id' => $event->id]);
    }

    public function test_a_batch_second_yellow_for_a_coach_creates_a_yellow_and_a_red(): void
    {
        $user = User::factory()->create();
        [$match, $homeCoach] = $this->makeMatchWithCoaches($user);

        $this->actingAs($user)->post(route('matches.events.batch-store', $match), [
            'events' => [
                ['type' => 'yellow_card', 'coach_id' => $homeCoach->id],
                ['type' => 'red_card', 'coach_id' => $homeCoach->id],
            ],
        ])->assertRedirect(route('matches.edit', $match));

        $events = MatchEvent::query()->where('match_id', $match->id)->where('coach_id', $homeCoach->id)->get();

        $this->assertCount(2, $events);
        $this->assertTrue($events->contains(fn (MatchEvent $event): bool => $event->type === MatchEventType::YellowCard));
        $this->assertTrue($events->contains(fn (MatchEvent $event): bool => $event->type === MatchEventType::RedCard));
    }

    public function test_a_user_cannot_register_a_coach_card_on_another_users_match(): void
    {
        $owner = User::factory()->create();
        [$match, $homeCoach] = $this->makeMatchWithCoaches($owner);

        $intruder = User::factory()->create();

        $this->actingAs($intruder)->post(route('matches.events.store', $match), [
            'type' => 'yellow_card',
            'coach_id' => $homeCoach->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('match_events', ['coach_id' => $homeCoach->id]);
    }

    // ── Guardado por lote (paneles de alta rápida) ──────────────────────

    public function test_a_user_can_save_several_queued_events_in_one_batch_request(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);
        $homeAssister = Player::factory()->for($match->homeTeam)->create(['jersey_number' => 11]);

        $this->actingAs($user)->post(route('matches.events.batch-store', $match), [
            'events' => [
                ['type' => 'goal', 'player_id' => $homePlayer->id],
                ['type' => 'assist', 'player_id' => $homeAssister->id],
                ['type' => 'yellow_card', 'player_id' => $homePlayer->id],
            ],
        ])->assertRedirect(route('matches.edit', $match));

        $this->assertSame(3, MatchEvent::query()->where('match_id', $match->id)->count());
        $this->assertDatabaseHas('match_events', ['match_id' => $match->id, 'player_id' => $homePlayer->id, 'type' => 'goal']);
        $this->assertDatabaseHas('match_events', ['match_id' => $match->id, 'player_id' => $homeAssister->id, 'type' => 'assist']);
        $this->assertDatabaseHas('match_events', ['match_id' => $match->id, 'player_id' => $homePlayer->id, 'type' => 'yellow_card']);
    }

    public function test_a_batch_cannot_register_more_assists_than_goals_for_a_team(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);
        $homeAssister = Player::factory()->for($match->homeTeam)->create(['jersey_number' => 11]);

        $this->actingAs($user)->post(route('matches.events.batch-store', $match), [
            'events' => [
                ['type' => 'assist', 'player_id' => $homeAssister->id],
            ],
        ])->assertSessionHasErrors('events.0.type');

        $this->assertDatabaseMissing('match_events', ['player_id' => $homeAssister->id]);

        // A goal and its own assist queued together in the SAME batch must
        // still be allowed -- the check tallies the whole submit, not row
        // by row, since that's exactly what the quick-add panel sends when
        // both icons are clicked before "Guardar eventos".
        $this->actingAs($user)->post(route('matches.events.batch-store', $match), [
            'events' => [
                ['type' => 'goal', 'player_id' => $homePlayer->id],
                ['type' => 'assist', 'player_id' => $homeAssister->id],
            ],
        ])->assertRedirect(route('matches.edit', $match));

        $this->assertDatabaseHas('match_events', ['player_id' => $homeAssister->id, 'type' => 'assist']);
    }

    public function test_a_batch_cannot_register_a_goal_and_its_only_assist_for_the_same_player(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);

        $this->actingAs($user)->post(route('matches.events.batch-store', $match), [
            'events' => [
                ['type' => 'goal', 'player_id' => $homePlayer->id],
                ['type' => 'assist', 'player_id' => $homePlayer->id],
            ],
        ])->assertSessionHasErrors('events.1.type');

        $this->assertDatabaseMissing('match_events', ['player_id' => $homePlayer->id]);
    }

    public function test_a_rejected_batch_repopulates_the_pending_queue_instead_of_losing_it(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);
        $homeAssister = Player::factory()->for($match->homeTeam)->create(['jersey_number' => 11]);

        $this->actingAs($user)->post(route('matches.events.batch-store', $match), [
            'events' => [
                ['type' => 'goal', 'player_id' => $homePlayer->id],
                ['type' => 'assist', 'player_id' => $homeAssister->id],
                ['type' => 'assist', 'player_id' => $homeAssister->id],
            ],
        ])->assertSessionHasErrors('events.2.type');

        $this->assertDatabaseMissing('match_events', ['player_id' => $homeAssister->id]);

        // The redirect back re-renders the page with old('events') still
        // flashed -- the queue should come back exactly as it was (the
        // valid goal, plus the two assist clicks accumulated into one "2x"
        // entry), not empty, so the user only has to fix what was rejected.
        $response = $this->actingAs($user)->get(route('matches.edit', $match));

        $response->assertViewHas('oldQueuedEvents', function (array $oldQueuedEvents) use ($homePlayer, $homeAssister): bool {
            $goal = collect($oldQueuedEvents)->firstWhere('subjectId', $homePlayer->id);
            $assist = collect($oldQueuedEvents)->firstWhere('subjectId', $homeAssister->id);

            return $goal !== null && $goal['type'] === 'goal' && $goal['count'] === 1
                && $assist !== null && $assist['type'] === 'assist' && $assist['count'] === 2;
        });
    }

    public function test_a_second_yellow_queued_as_two_events_creates_a_yellow_and_a_red(): void
    {
        // Mirrors what the quick-add panel actually queues when "Amarilla"
        // is clicked twice for the same player -- two independent events,
        // no special flag needed server-side.
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);

        $this->actingAs($user)->post(route('matches.events.batch-store', $match), [
            'events' => [
                ['type' => 'yellow_card', 'player_id' => $homePlayer->id],
                ['type' => 'red_card', 'player_id' => $homePlayer->id],
            ],
        ])->assertRedirect(route('matches.edit', $match));

        $events = MatchEvent::query()->where('match_id', $match->id)->where('player_id', $homePlayer->id)->get();

        $this->assertCount(2, $events);
        $this->assertTrue($events->contains(fn (MatchEvent $event): bool => $event->type === MatchEventType::YellowCard));
        $this->assertTrue($events->contains(fn (MatchEvent $event): bool => $event->type === MatchEventType::RedCard));
    }

    public function test_a_batch_containing_an_outside_player_is_rejected_entirely(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer, , $outsiderPlayer] = $this->makeMatchWithPlayers($user);

        $this->actingAs($user)->post(route('matches.events.batch-store', $match), [
            'events' => [
                ['type' => 'goal', 'player_id' => $homePlayer->id],
                ['type' => 'goal', 'player_id' => $outsiderPlayer->id],
            ],
        ])->assertSessionHasErrors('events.1.player_id');

        // The whole batch is rejected by validation before anything is
        // persisted -- not even the valid entry should have been saved.
        $this->assertSame(0, MatchEvent::query()->where('match_id', $match->id)->count());
    }

    public function test_an_empty_batch_is_rejected(): void
    {
        $user = User::factory()->create();
        [$match] = $this->makeMatchWithPlayers($user);

        $this->actingAs($user)->post(route('matches.events.batch-store', $match), [
            'events' => [],
        ])->assertSessionHasErrors('events');
    }

    public function test_a_user_cannot_batch_save_events_on_another_users_match(): void
    {
        $owner = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($owner);

        $intruder = User::factory()->create();

        $this->actingAs($intruder)->post(route('matches.events.batch-store', $match), [
            'events' => [
                ['type' => 'goal', 'player_id' => $homePlayer->id],
            ],
        ])->assertForbidden();

        $this->assertSame(0, MatchEvent::query()->where('match_id', $match->id)->count());
    }

    // ── Autorización ─────────────────────────────────────────────────────

    public function test_a_user_cannot_create_an_event_on_another_users_match(): void
    {
        $owner = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($owner);

        $intruder = User::factory()->create();

        $this->actingAs($intruder)->post(route('matches.events.store', $match), [
            'type' => 'goal',
            'player_id' => $homePlayer->id,
            'minute' => 12,
        ])->assertForbidden();

        $this->assertDatabaseMissing('match_events', ['match_id' => $match->id]);
    }

    public function test_a_user_cannot_delete_an_event_of_another_users_match(): void
    {
        $owner = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($owner);

        $event = MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $homePlayer->team_id,
            'player_id' => $homePlayer->id,
            'type' => MatchEventType::Goal,
        ]);

        $intruder = User::factory()->create();

        $this->actingAs($intruder)->delete(route('events.destroy', $event))->assertForbidden();

        $this->assertDatabaseHas('match_events', ['id' => $event->id]);
    }

    // ── Consistencia con el marcador ─────────────────────────────────────

    public function test_editing_the_match_result_does_not_alter_existing_goal_events(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);

        $this->actingAs($user)->post(route('matches.events.store', $match), [
            'type' => 'goal', 'player_id' => $homePlayer->id, 'minute' => 10,
        ]);

        $this->actingAs($user)->patch(route('matches.result.update', $match), [
            'home_score' => 3, 'away_score' => 1,
        ]);

        $this->assertSame(1, MatchEvent::query()->where('match_id', $match->id)->count());
    }

    public function test_deleting_a_goal_event_does_not_change_the_matchs_scoreboard(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);

        $this->actingAs($user)->patch(route('matches.result.update', $match), [
            'home_score' => 2, 'away_score' => 0,
        ]);

        $event = MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $homePlayer->team_id,
            'player_id' => $homePlayer->id,
            'type' => MatchEventType::Goal,
        ]);

        $this->actingAs($user)->delete(route('events.destroy', $event));

        $match->refresh();
        $this->assertSame(2, $match->home_score);
        $this->assertSame(0, $match->away_score);
    }

    public function test_the_match_edit_page_flags_a_mismatch_between_goal_events_and_the_scoreboard(): void
    {
        $user = User::factory()->create();
        [$match, $homePlayer] = $this->makeMatchWithPlayers($user);

        $this->actingAs($user)->patch(route('matches.result.update', $match), [
            'home_score' => 3, 'away_score' => 1,
        ]);

        MatchEvent::factory()->create([
            'match_id' => $match->id,
            'team_id' => $homePlayer->team_id,
            'player_id' => $homePlayer->id,
            'type' => MatchEventType::Goal,
        ]);

        $response = $this->actingAs($user)->get(route('matches.edit', $match));

        $response->assertOk()->assertSeeText('no coinciden con el marcador');
    }
}
