<?php

namespace Tests\Feature\Tournaments;

use App\Enums\RosterEndReason;
use App\Enums\RosterStartReason;
use App\Models\Category;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlayerTeamHistory;
use App\Models\Team;
use App\Models\User;
use App\Services\PlayerRosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Every change to a player's planteles leaves a line in player_team_history
 * (when it started and ended and why), written by PlayerRosterService in the
 * same step that changes the roster itself.
 */
class PlayerRosterHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-10 09:00:00');
    }

    private function makeTeamForClub(Club $club, string $categoryName, ?int $birthYearTo = null, ?string $teamName = null): Team
    {
        $category = Category::factory()->create([
            'tournament_id' => null,
            'user_id' => $club->user_id,
            'name' => $categoryName,
            'uses_groups' => false,
            'birth_year_to' => $birthYearTo,
        ]);

        return Team::factory()->create([
            'club_id' => $club->id,
            'category_id' => $category->id,
            'tournament_id' => null,
            'group_id' => null,
            'name' => $teamName ?? $club->name,
        ]);
    }

    /**
     * @return array{0: PlayerTeamHistory, ...}
     */
    private function lines(Player $player): array
    {
        return $player->teamHistory()->reorder('id')->get()->all();
    }

    public function test_registering_a_new_player_on_a_plantel_opens_their_first_line(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create(['name' => 'Nilmar']);
        $team = $this->makeTeamForClub($club, 'Infantil');

        $this->actingAs($user)
            ->post(route('teams.players.store', $team), ['full_name' => 'Ana Ruiz', 'document_number' => '111', 'jersey_number' => 7, 'birth_date' => '2012-01-01'])
            ->assertRedirect(route('teams.show', $team));

        $player = Player::query()->where('document_number', '111')->firstOrFail();
        [$line] = $this->lines($player);

        $this->assertCount(1, $this->lines($player));
        $this->assertSame($team->id, $line->team_id);
        $this->assertSame($club->id, $line->club_id);
        $this->assertSame('NILMAR', $line->club_name);
        $this->assertSame('INFANTIL', $line->category_name);
        $this->assertSame(7, $line->jersey_number);
        $this->assertSame('2026-05-10', $line->started_on->toDateString());
        $this->assertNull($line->ended_on);
        $this->assertSame(RosterStartReason::Registered, $line->start_reason);
        $this->assertFalse($line->is_estimated);
    }

    public function test_the_club_level_form_opens_a_line_for_the_primary_and_each_extra_plantel(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $infantil = $this->makeTeamForClub($club, 'Infantil');
        $juvenil = $this->makeTeamForClub($club, 'Juvenil');

        $this->actingAs($user)->post(route('clubs.players.store', $club), [
            'full_name' => 'Doble Categoria',
            'birth_date' => '2012-01-01',
            'team_ids' => [$infantil->id, $juvenil->id],
        ]);

        $player = Player::query()->where('full_name', 'DOBLE CATEGORIA')->firstOrFail();

        $this->assertEqualsCanonicalizing([$infantil->id, $juvenil->id], collect($this->lines($player))->pluck('team_id')->all());
        $this->assertTrue(collect($this->lines($player))->every(fn ($line) => $line->isOpen() && $line->start_reason === RosterStartReason::Registered));
    }

    public function test_adding_a_player_to_another_plantel_of_the_same_club_opens_an_additional_line(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $infantil = $this->makeTeamForClub($club, 'Infantil', 2013);
        $juvenil = $this->makeTeamForClub($club, 'Juvenil', 2009);

        $this->actingAs($user)->post(route('teams.players.store', $infantil), ['full_name' => 'Luis Mora', 'document_number' => '222', 'birth_date' => '2010-01-01']);
        $player = Player::query()->where('document_number', '222')->firstOrFail();

        $this->actingAs($user)->post(route('teams.players.store', $juvenil), ['full_name' => 'Luis Mora', 'document_number' => '222', 'jersey_number' => 9, 'birth_date' => '2010-01-01']);

        $lines = $this->lines($player);
        $this->assertCount(2, $lines);
        $this->assertSame(RosterStartReason::Added, $lines[1]->start_reason);
        $this->assertSame($juvenil->id, $lines[1]->team_id);
        $this->assertSame(9, $lines[1]->jersey_number);
        // The first line is untouched.
        $this->assertTrue($lines[0]->isOpen());
    }

    public function test_removing_a_player_from_an_extra_plantel_closes_its_line(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $infantil = $this->makeTeamForClub($club, 'Infantil', 2013);
        $juvenil = $this->makeTeamForClub($club, 'Juvenil', 2009);

        $this->actingAs($user)->post(route('teams.players.store', $infantil), ['full_name' => 'Luis Mora', 'document_number' => '222', 'birth_date' => '2010-01-01']);
        $player = Player::query()->where('document_number', '222')->firstOrFail();
        $this->actingAs($user)->post(route('teams.players.store', $juvenil), ['full_name' => 'Luis Mora', 'document_number' => '222', 'birth_date' => '2010-01-01']);

        Carbon::setTestNow('2026-06-01 09:00:00');

        $this->actingAs($user)->delete(route('players.teams.destroy', [$player, $juvenil]))->assertRedirect();

        $closed = collect($this->lines($player))->firstWhere('team_id', $juvenil->id);
        $this->assertSame('2026-06-01', $closed->ended_on->toDateString());
        $this->assertSame(RosterEndReason::Removed, $closed->end_reason);
        $this->assertTrue(collect($this->lines($player))->firstWhere('team_id', $infantil->id)->isOpen());
    }

    public function test_the_edit_form_adding_planteles_opens_lines_for_them(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $infantil = $this->makeTeamForClub($club, 'Infantil', 2013);
        $juvenil = $this->makeTeamForClub($club, 'Juvenil', 2009);

        $this->actingAs($user)->post(route('teams.players.store', $infantil), ['full_name' => 'Luis Mora', 'document_number' => '222', 'birth_date' => '2010-01-01']);
        $player = Player::query()->where('document_number', '222')->firstOrFail();

        $this->actingAs($user)->put(route('players.update', $player), [
            'full_name' => 'Luis Mora', 'document_number' => '222', 'birth_date' => '2010-01-01', 'team_ids' => [$juvenil->id],
        ]);

        $line = collect($this->lines($player))->firstWhere('team_id', $juvenil->id);
        $this->assertNotNull($line);
        $this->assertSame(RosterStartReason::Added, $line->start_reason);
    }

    public function test_moving_a_player_to_another_club_closes_every_old_line_and_opens_the_new_one(): void
    {
        $user = User::factory()->create();
        $clubA = Club::factory()->for($user)->create(['name' => 'Club Origen']);
        $clubB = Club::factory()->for($user)->create(['name' => 'Club Destino']);
        $teamA = $this->makeTeamForClub($clubA, 'Infantil');
        $teamAExtra = $this->makeTeamForClub($clubA, 'Cebollita');
        $teamB = $this->makeTeamForClub($clubB, 'Infantil');

        $this->actingAs($user)->post(route('teams.players.store', $teamA), ['full_name' => 'Mover Me', 'document_number' => '999', 'birth_date' => '2012-01-01']);
        $player = Player::query()->where('document_number', '999')->firstOrFail();
        $this->actingAs($user)->post(route('teams.players.store', $teamAExtra), ['full_name' => 'Mover Me', 'document_number' => '999', 'birth_date' => '2012-01-01']);
        $player->update(['is_active' => false]);
        $player->forceFill(['is_active' => false])->save();

        Carbon::setTestNow('2026-08-01 09:00:00');

        $this->actingAs($user)->post(route('teams.players.store', $teamB), ['document_number' => '999', 'full_name' => 'Mover Me'])->assertRedirect(route('teams.show', $teamB));

        $lines = collect($this->lines($player));
        $old = $lines->whereIn('team_id', [$teamA->id, $teamAExtra->id]);

        $this->assertCount(2, $old);
        $this->assertTrue($old->every(fn ($line) => $line->ended_on?->toDateString() === '2026-08-01' && $line->end_reason === RosterEndReason::Transferred));

        $new = $lines->firstWhere('team_id', $teamB->id);
        $this->assertTrue($new->isOpen());
        $this->assertSame(RosterStartReason::Transferred, $new->start_reason);
        $this->assertSame('CLUB DESTINO', $new->club_name);
        $this->assertSame('2026-08-01', $new->started_on->toDateString());
    }

    public function test_the_club_level_form_moving_a_player_also_records_a_transfer(): void
    {
        $user = User::factory()->create();
        $clubA = Club::factory()->for($user)->create();
        $clubB = Club::factory()->for($user)->create();
        $teamA = $this->makeTeamForClub($clubA, 'Infantil');
        $teamB = $this->makeTeamForClub($clubB, 'Infantil');
        $teamBExtra = $this->makeTeamForClub($clubB, 'Juvenil');

        $this->actingAs($user)->post(route('teams.players.store', $teamA), ['full_name' => 'Mover Me', 'document_number' => '1111', 'birth_date' => '2012-01-01']);
        $player = Player::query()->where('document_number', '1111')->firstOrFail();
        $player->forceFill(['is_active' => false])->save();

        $this->actingAs($user)->post(route('clubs.players.store', $clubB), [
            'document_number' => '1111', 'full_name' => 'Mover Me', 'birth_date' => '2012-01-01', 'team_ids' => [$teamB->id, $teamBExtra->id],
        ])->assertRedirect(route('clubs.show', $clubB));

        $lines = collect($this->lines($player));

        $this->assertSame(RosterEndReason::Transferred, $lines->firstWhere('team_id', $teamA->id)->end_reason);
        $this->assertTrue($lines->whereIn('team_id', [$teamB->id, $teamBExtra->id])->every(fn ($line) => $line->isOpen() && $line->start_reason === RosterStartReason::Transferred));
    }

    public function test_promoting_a_player_closes_the_old_line_and_opens_the_new_one(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $young = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Infantil', 'birth_year_from' => 2012, 'birth_year_to' => 2013]);
        $older = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Juvenil', 'birth_year_from' => 2008, 'birth_year_to' => 2009]);
        $origin = Team::factory()->create(['club_id' => $club->id, 'category_id' => $young->id, 'tournament_id' => null, 'group_id' => null]);
        $destination = Team::factory()->create(['club_id' => $club->id, 'category_id' => $older->id, 'tournament_id' => null, 'group_id' => null]);

        $this->actingAs($user)->post(route('teams.players.store', $origin), ['full_name' => 'Crece Mucho', 'document_number' => '333', 'birth_date' => '2010-01-01']);
        $player = Player::query()->where('document_number', '333')->firstOrFail();

        Carbon::setTestNow('2026-09-01 09:00:00');

        $this->actingAs($user)->post(route('teams.players.promote.store', [$origin, $player]))->assertRedirect(route('teams.show', $origin));

        $lines = collect($this->lines($player));
        $from = $lines->firstWhere('team_id', $origin->id);
        $to = $lines->firstWhere('team_id', $destination->id);

        $this->assertSame(RosterEndReason::Promoted, $from->end_reason);
        $this->assertSame('2026-09-01', $from->ended_on->toDateString());
        $this->assertTrue($to->isOpen());
        $this->assertSame(RosterStartReason::Promoted, $to->start_reason);
    }

    public function test_promoting_through_a_pivot_link_keeps_the_jersey_number_on_the_new_line(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $primaryTeam = $this->makeTeamForClub($club, 'Cebollita', 2018);
        $young = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Infantil', 'birth_year_to' => 2013]);
        $older = Category::factory()->create(['tournament_id' => null, 'user_id' => $user->id, 'name' => 'Juvenil', 'birth_year_to' => 2009]);
        $origin = Team::factory()->create(['club_id' => $club->id, 'category_id' => $young->id, 'tournament_id' => null, 'group_id' => null]);
        $destination = Team::factory()->create(['club_id' => $club->id, 'category_id' => $older->id, 'tournament_id' => null, 'group_id' => null]);

        $player = Player::factory()->for($primaryTeam)->create(['birth_date' => '2010-01-01', 'document_number' => '444']);
        $player->teams()->attach($origin->id, ['jersey_number' => 7]);

        $this->actingAs($user)->post(route('teams.players.promote.store', [$origin, $player]));

        $to = $player->teamHistory()->where('team_id', $destination->id)->firstOrFail();
        $this->assertSame(7, $to->jersey_number);
    }

    public function test_removing_a_player_from_a_club_closes_their_lines_there(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $team = $this->makeTeamForClub($club, 'Infantil');

        $this->actingAs($user)->post(route('teams.players.store', $team), ['full_name' => 'Se Va', 'document_number' => '555', 'birth_date' => '2012-01-01']);
        $player = Player::query()->where('document_number', '555')->firstOrFail();
        $otherTeam = $this->makeTeamForClub(Club::factory()->for($user)->create(), 'Infantil');
        // A second club link keeps the player row alive when this club's are removed.
        $player->teams()->attach($otherTeam->id);
        $player->teamHistory()->create(['team_id' => $otherTeam->id, 'club_id' => $otherTeam->club_id, 'club_name' => 'Otro', 'team_name' => 'Otro', 'started_on' => '2026-01-01', 'start_reason' => RosterStartReason::Added]);

        $this->actingAs($user)->delete(route('clubs.players.destroy', [$club, $player]))->assertRedirect();

        $line = collect($this->lines($player->fresh()))->firstWhere('team_id', $team->id);
        $this->assertSame(RosterEndReason::Removed, $line->end_reason);
        // The other club's line stays open, and the player became primary there.
        $this->assertTrue(collect($this->lines($player->fresh()))->firstWhere('team_id', $otherTeam->id)->isOpen());
        $this->assertSame($otherTeam->id, $player->fresh()->team_id);
    }

    public function test_the_history_keeps_reading_right_after_the_plantel_is_renamed_or_deleted(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create(['name' => 'Nilmar']);
        $team = $this->makeTeamForClub($club, 'Infantil');
        $primary = $this->makeTeamForClub(Club::factory()->for($user)->create(), 'Cebollita');
        $player = Player::factory()->for($primary)->create();
        $player->teams()->attach($team->id);
        $player->teamHistory()->create([
            'team_id' => $team->id, 'club_id' => $club->id, 'club_name' => 'NILMAR', 'team_name' => 'NILMAR', 'category_name' => 'INFANTIL',
            'started_on' => '2026-01-01', 'start_reason' => RosterStartReason::Added,
        ]);

        $this->actingAs($user)->delete(route('teams.destroy', $team))->assertRedirect();

        $line = $player->teamHistory()->firstOrFail();
        $this->assertNull($line->team_id);
        $this->assertSame('NILMAR', $line->club_name);
        $this->assertSame('INFANTIL', $line->category_name);
        $this->assertSame(RosterEndReason::TeamDeleted, $line->end_reason);
        $this->assertSame('2026-05-10', $line->ended_on->toDateString());
    }

    public function test_a_second_open_line_for_the_same_plantel_is_never_created(): void
    {
        $user = User::factory()->create();
        $club = Club::factory()->for($user)->create();
        $team = $this->makeTeamForClub($club, 'Infantil');
        $player = Player::factory()->for($team)->create();

        $service = app(PlayerRosterService::class);
        $service->open($player, $team, RosterStartReason::Registered);
        $service->open($player, $team, RosterStartReason::Registered);

        $this->assertSame(1, $player->teamHistory()->count());
    }
}
