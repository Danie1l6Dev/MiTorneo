<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchEventType;
use App\Enums\RosterEndReason;
use App\Enums\RosterStartReason;
use App\Enums\SanctionType;
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
use App\Services\PlayerProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Buscar jugador": the live search over the organizer's players (moved out of
 * Clubes) and the ficha a result opens -- stats per tournament, cards,
 * sanctions and the clubs/planteles timeline (recorded history, with what it
 * lacks deduced and flagged estimated).
 */
class PlayerSearchAndProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A player who is now at Nilmar (his current plantel, enrolled in the
     * tournament) but whose goals, assist, cards and one sanction were all
     * recorded while at Halcones FC -- a past club that only those records
     * still tell about.
     *
     * @return array{user: User, tournament: Tournament, player: Player, teamNow: Team, teamBefore: Team, match: TournamentMatch, redCard: MatchEvent}
     */
    private function scenario(): array
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create(['name' => 'Copa Maicao']);
        $category = Category::factory()->for($tournament)->create(['name' => 'Sub-13', 'uses_groups' => false]);
        $phase = CompetitionPhase::factory()->for($tournament)->for($category)->create();

        $halcones = Club::factory()->for($user)->create(['name' => 'Halcones FC']);
        $nilmar = Club::factory()->for($user)->create(['name' => 'Nilmar']);

        $teamBefore = Team::factory()->for($tournament)->for($category)->create(['club_id' => $halcones->id, 'name' => 'Halcones FC']);
        $teamNow = Team::factory()->for($tournament)->for($category)->create(['club_id' => $nilmar->id, 'name' => 'Nilmar']);
        $rival = Team::factory()->for($tournament)->for($category)->create(['name' => 'Rival']);

        $player = Player::factory()->create([
            'team_id' => $teamNow->id,
            'full_name' => 'Juan Pérez Gómez',
            'document_number' => '1234567',
            'jersey_number' => 9,
            'birth_date' => '2013-05-04',
        ]);

        $match = TournamentMatch::factory()->for($phase)->create([
            'home_team_id' => $teamBefore->id,
            'away_team_id' => $rival->id,
            'scheduled_at' => '2026-03-01 10:00:00',
        ]);

        $event = fn (MatchEventType $type): MatchEvent => MatchEvent::factory()->create([
            'match_id' => $match->id, 'team_id' => $teamBefore->id, 'player_id' => $player->id, 'type' => $type,
        ]);

        $event(MatchEventType::Goal);
        $event(MatchEventType::Goal);
        $event(MatchEventType::Assist);
        $event(MatchEventType::YellowCard);
        $redCard = $event(MatchEventType::RedCard);

        Sanction::factory()->resolved(2)->create([
            'match_id' => $match->id,
            'match_event_id' => $redCard->id,
            'team_id' => $teamBefore->id,
            'player_id' => $player->id,
            'type' => SanctionType::RedCard,
            'resolution_notes' => 'Reincidente en la temporada',
        ]);

        return compact('user', 'tournament', 'player', 'teamNow', 'teamBefore', 'match', 'redCard');
    }

    // ── Search ───────────────────────────────────────────────────────────

    public function test_the_catalog_makes_a_player_findable_by_document_name_surname_and_any_club_they_had(): void
    {
        $data = $this->scenario();

        $catalog = app(PlayerProfileService::class)->searchCatalog($data['user']->id);
        $entry = collect($catalog)->firstWhere('id', $data['player']->id);

        // Lowercase and without accents, all in one string to match words against.
        foreach (['1234567', 'juan', 'perez', 'gomez', 'nilmar', 'halcones fc'] as $word) {
            $this->assertStringContainsString($word, $entry['haystack']);
        }

        $this->assertSame('JUAN PÉREZ GÓMEZ', $entry['name']);
        $this->assertSame('1234567', $entry['document']);
        $this->assertSame([['club' => 'NILMAR', 'category' => 'SUB-13']], $entry['teams']);
        // The past club is only known from his events and sanctions.
        $this->assertSame(['HALCONES FC'], $entry['past_clubs']);
        $this->assertSame(route('players.show', $data['player']), $entry['url']);
        $this->assertFalse($entry['inactive']);
    }

    public function test_the_catalog_only_holds_the_organizers_own_players(): void
    {
        $data = $this->scenario();
        $stranger = $this->scenario();

        $ids = collect(app(PlayerProfileService::class)->searchCatalog($data['user']->id))->pluck('id');

        $this->assertTrue($ids->contains($data['player']->id));
        $this->assertFalse($ids->contains($stranger['player']->id));
    }

    public function test_the_search_page_sends_the_catalog_once_and_filters_it_in_the_browser(): void
    {
        $data = $this->scenario();

        $response = $this->actingAs($data['user'])->get(route('players.index'))->assertOk();

        $response
            ->assertSee('Buscar jugador')
            ->assertSee('Documento, nombre, apellido o club...', false)
            ->assertSee('x-model="query"', false)
            ->assertSee('player.haystack.includes(word)', false)
            // Each result opens the ficha in the same tab, through the app's own navigation
            // (never a new browser tab), and the typed text is kept in the address.
            ->assertSee(':href="player.url"', false)
            ->assertSee('wire:navigate', false)
            ->assertDontSee('target="_blank"', false)
            ->assertSee("get('q')", false)
            ->assertSee('Escribe un documento, un nombre o un club para buscar.');

        $this->assertSame($data['player']->id, $response->viewData('catalog')[0]['id']);
    }

    public function test_the_sidebar_offers_buscar_jugador(): void
    {
        $data = $this->scenario();

        $this->actingAs($data['user'])
            ->get(route('categories.index'))
            ->assertOk()
            ->assertSee(route('players.index'), false)
            ->assertSee('Buscar jugador');
    }

    public function test_clubes_no_longer_has_a_players_tab_and_the_old_address_goes_to_the_new_section(): void
    {
        $data = $this->scenario();

        $this->actingAs($data['user'])
            ->get(route('clubs.index', ['view' => 'jugadores']))
            ->assertRedirect(route('players.index'));

        $this->actingAs($data['user'])
            ->get(route('clubs.index'))
            ->assertOk()
            ->assertDontSee('Nombre o documento del jugador...', false)
            ->assertDontSee("view === 'jugadores'", false);
    }

    public function test_the_players_search_endpoint_used_by_the_roster_forms_is_untouched(): void
    {
        $data = $this->scenario();

        // /players/search must still reach the roster forms' lookup, not be
        // swallowed by /players/{player}.
        $this->actingAs($data['user'])
            ->getJson(route('players.search', ['document_number' => '1234567']))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('player.full_name', 'JUAN PÉREZ GÓMEZ');
    }

    // ── Ficha ────────────────────────────────────────────────────────────

    public function test_the_ficha_totals_every_goal_assist_card_and_sanction(): void
    {
        $data = $this->scenario();

        $profile = app(PlayerProfileService::class)->profile($data['player']);

        $this->assertSame(
            ['goals' => 2, 'assists' => 1, 'yellow_cards' => 1, 'red_cards' => 1, 'sanctions' => 1, 'tournaments' => 1],
            $profile['totals'],
        );
    }

    public function test_the_ficha_lists_tournaments_by_plantel_including_the_current_one_without_events(): void
    {
        $data = $this->scenario();

        $rows = app(PlayerProfileService::class)->profile($data['player'])['tournaments'];

        // The past plantel (all his events) and the current one (enrolled, no events yet).
        $this->assertCount(2, $rows);

        $before = collect($rows)->firstWhere('team.id', $data['teamBefore']->id);
        $this->assertSame(['goals' => 2, 'assists' => 1, 'yellow_cards' => 1, 'red_cards' => 1], collect($before)->only(['goals', 'assists', 'yellow_cards', 'red_cards'])->all());
        $this->assertFalse($before['current']);
        $this->assertSame('2026-03-01', $before['last_at']->toDateString());

        $now = collect($rows)->firstWhere('team.id', $data['teamNow']->id);
        $this->assertTrue($now['current']);
        $this->assertSame(0, $now['goals']);
        $this->assertNull($now['last_at']);
    }

    public function test_without_a_recorded_history_the_timeline_is_deduced_and_flagged_estimated(): void
    {
        $data = $this->scenario();

        $timeline = app(PlayerProfileService::class)->profile($data['player'])['timeline'];

        $this->assertSame(['NILMAR', 'HALCONES FC'], array_column($timeline, 'club'));
        $this->assertSame([true, false], array_column($timeline, 'current'));
        $this->assertSame(9, $timeline[0]['jersey']);
        $this->assertSame(['COPA MAICAO'], $timeline[1]['tournaments']);
        $this->assertSame([true, true], array_column($timeline, 'estimated'));
        $this->assertSame('2026-03-01', $timeline[1]['from']->toDateString());
    }

    public function test_a_recorded_history_shows_real_dates_and_reasons_and_only_deduces_what_it_lacks(): void
    {
        $data = $this->scenario();

        // History that only knows about the current plantel (a transfer from somewhere else on 2026-04-01).
        PlayerTeamHistory::factory()->create([
            'player_id' => $data['player']->id, 'team_id' => $data['teamNow']->id, 'club_id' => $data['teamNow']->club_id,
            'club_name' => 'NILMAR', 'team_name' => 'NILMAR', 'category_name' => 'SUB-13', 'jersey_number' => 9,
            'started_on' => '2026-04-01', 'start_reason' => RosterStartReason::Transferred, 'notes' => 'Cambio de ciudad',
        ]);

        $timeline = app(PlayerProfileService::class)->profile($data['player'])['timeline'];

        // The recorded line is real...
        $now = collect($timeline)->firstWhere('club', 'NILMAR');
        $this->assertFalse($now['estimated']);
        $this->assertTrue($now['current']);
        $this->assertSame('2026-04-01', $now['from']->toDateString());
        $this->assertSame('Transferido desde otro club', $now['start_reason']);
        $this->assertSame('Cambio de ciudad', $now['notes']);
        $this->assertSame(9, $now['jersey']);

        // ...and Halcones, which has no line, is still deduced from the events.
        $before = collect($timeline)->firstWhere('club', 'HALCONES FC');
        $this->assertTrue($before['estimated']);
        $this->assertFalse($before['current']);
        $this->assertSame(['COPA MAICAO'], $before['tournaments']);
        $this->assertCount(2, $timeline);
    }

    public function test_a_closed_recorded_line_carries_its_dates_reasons_and_the_tournaments_played_there(): void
    {
        $data = $this->scenario();

        PlayerTeamHistory::factory()->ended('2026-03-05', RosterEndReason::Transferred)->create([
            'player_id' => $data['player']->id, 'team_id' => $data['teamBefore']->id, 'club_id' => $data['teamBefore']->club_id,
            'club_name' => 'HALCONES FC', 'team_name' => 'HALCONES FC', 'category_name' => 'SUB-13',
            'started_on' => '2025-08-01', 'start_reason' => RosterStartReason::Registered,
        ]);

        $entry = collect(app(PlayerProfileService::class)->profile($data['player'])['timeline'])->firstWhere('club', 'HALCONES FC');

        $this->assertFalse($entry['estimated']);
        $this->assertSame('2025-08-01', $entry['from']->toDateString());
        $this->assertSame('2026-03-05', $entry['to']->toDateString());
        $this->assertSame('Alta', $entry['start_reason']);
        $this->assertSame('Transferido a otro club', $entry['end_reason']);
        // The tournaments still come from the events logged for that plantel.
        $this->assertSame(['COPA MAICAO'], $entry['tournaments']);
    }

    public function test_the_search_finds_a_player_by_a_club_only_the_history_knows(): void
    {
        $data = $this->scenario();
        // A past club the events never mention -- only the recorded history does.
        PlayerTeamHistory::factory()->ended()->create([
            'player_id' => $data['player']->id, 'club_name' => 'ATLÉTICO REMOTO', 'team_name' => 'ATLÉTICO REMOTO',
        ]);

        $entry = collect(app(PlayerProfileService::class)->searchCatalog($data['user']->id))->firstWhere('id', $data['player']->id);

        $this->assertContains('ATLÉTICO REMOTO', $entry['past_clubs']);
        $this->assertStringContainsString('atletico remoto', $entry['haystack']);
    }

    public function test_the_ficha_page_shows_the_whole_record(): void
    {
        $data = $this->scenario();

        $this->actingAs($data['user'])
            ->get(route('players.show', $data['player']))
            ->assertOk()
            ->assertSee('JUAN PÉREZ GÓMEZ')
            ->assertSee('Documento: 1234567')
            ->assertSee('04/05/2013')
            ->assertSee('Torneos jugados')
            ->assertSee('COPA MAICAO')
            ->assertSee('Historial de sanciones')
            ->assertSee('Roja directa')
            ->assertSee('Reincidente en la temporada')
            ->assertSee('2 fechas de suspensión')
            ->assertSee('Historial de clubes y planteles')
            ->assertSee('Estimado')
            // The current plantel is spelled out once, in the header...
            ->assertSee('NILMAR · SUB-13')
            ->assertSee('Dorsal 9')
            // ...and the timeline tells the past one apart.
            ->assertSee('HALCONES FC')
            ->assertSee('Anterior')
            ->assertSee('no hay conteo de partidos jugados')
            ->assertSee(route('players.edit', $data['player']), false)
            ->assertSee('Editar ficha');
    }

    public function test_a_player_with_no_record_still_gets_a_ficha_with_empty_states(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();
        $team = Team::factory()->for($tournament)->for(Category::factory()->for($tournament))->create();
        $player = Player::factory()->create(['team_id' => $team->id, 'full_name' => 'Ana Sin Historia', 'is_active' => false]);

        $this->actingAs($user)
            ->get(route('players.show', $player))
            ->assertOk()
            ->assertSee('ANA SIN HISTORIA')
            ->assertSee('Inactivo')
            ->assertSee('Este jugador no tiene sanciones registradas.');
    }

    public function test_another_organizer_cannot_open_the_ficha_and_guests_are_sent_to_login(): void
    {
        $data = $this->scenario();

        $this->actingAs(User::factory()->create())->get(route('players.show', $data['player']))->assertForbidden();

        auth()->logout();
        $this->get(route('players.show', $data['player']))->assertRedirect(route('login'));
        $this->get(route('players.index'))->assertRedirect(route('login'));
    }

    public function test_an_unknown_player_is_a_404(): void
    {
        $data = $this->scenario();

        $this->actingAs($data['user'])->get(route('players.show', 999999))->assertNotFound();
    }
}
