<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchStatus;
use App\Models\Category;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Models\Venue;
use App\Services\MatchProgrammingPlannerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Tournaments\Concerns\MakesSchedulableMatches;
use Tests\TestCase;

/**
 * "Programar fecha": mass-assigning days, hours and canchas to the pending
 * matches of one fecha (TournamentProgrammingController +
 * MatchProgrammingPlannerService).
 */
class MatchProgrammingToolTest extends TestCase
{
    use MakesSchedulableMatches, RefreshDatabase;

    /**
     * Fecha 5 of a tournament with a Sub-13 (two matches, default 60 min) and
     * an older Sub-15 (one match), plus a fecha 6 match.
     *
     * @return array{user: User, tournament: Tournament, sub13: Category, sub15: Category, venue: Venue, matches: array<string, TournamentMatch>}
     */
    private function setUpFecha(): array
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $venue = $this->makeSchedulingVenue($user, 'Cancha Boscán');
        $sub13 = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $sub15 = $this->makeSchedulingCategory($tournament, 'Sub-15', 2011);

        $matches = [
            'tigres' => $this->makeSchedulingMatch($tournament, $sub13, 'Tigres', 'Leones', 5),
            'osos' => $this->makeSchedulingMatch($tournament, $sub13, 'Osos', 'Lobos', 5),
            'panteras' => $this->makeSchedulingMatch($tournament, $sub15, 'Panteras', 'Halcones', 5),
            'fecha6' => $this->makeSchedulingMatch($tournament, $sub13, 'Tigres', 'Osos', 6),
        ];

        return compact('user', 'tournament', 'sub13', 'sub15', 'venue', 'matches');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function previewPayload(array $data, array $overrides = []): array
    {
        return array_replace_recursive([
            'round' => 5,
            'rows' => [
                $data['sub13']->id.'-0' => ['date' => '2026-09-12', 'venue_id' => $data['venue']->id, 'start' => '07:30', 'rest' => ''],
                $data['sub15']->id.'-0' => ['date' => '2026-09-12', 'venue_id' => $data['venue']->id, 'start' => '07:30', 'rest' => '10'],
            ],
        ], $overrides);
    }

    // ── Planner ──────────────────────────────────────────────────────────

    public function test_the_planner_chains_kickoffs_and_stacks_categories_sharing_a_venue_and_day(): void
    {
        $data = $this->setUpFecha();
        $planner = app(MatchProgrammingPlannerService::class);

        $rows = $planner->rows($planner->candidates($data['tournament'], 5, false));
        $plan = $planner->plan($rows, $this->previewPayload($data)['rows']);

        $times = fn (TournamentMatch $match): string => $plan[$match->id]['at']->format('H:i');

        // Youngest category first (Sub-13), each match after the previous one...
        $this->assertSame('07:30', $times($data['matches']['tigres']));
        $this->assertSame('08:30', $times($data['matches']['osos']));
        // ...and the older category picks up where the first one ended (9:30),
        // even though its own first time was 7:30.
        $this->assertSame('09:30', $times($data['matches']['panteras']));
        $this->assertArrayNotHasKey($data['matches']['fecha6']->id, $plan);
    }

    public function test_kickoffs_are_spaced_by_the_category_duration_plus_the_optional_rest(): void
    {
        $data = $this->setUpFecha();
        $planner = app(MatchProgrammingPlannerService::class);
        $rows = $planner->rows($planner->candidates($data['tournament'], 5, false));

        $config = $this->previewPayload($data, ['rows' => [$data['sub13']->id.'-0' => ['rest' => '10']]])['rows'];
        $plan = $planner->plan($rows, $config);
        $times = fn (string $key): string => $plan[$data['matches'][$key]->id]['at']->format('H:i');

        // A 60-minute category: the next match starts 60 + 10 minutes later.
        $this->assertSame('07:30', $times('tigres'));
        $this->assertSame('08:40', $times('osos'));
        // The next category on the same cancha starts right after the rest.
        $this->assertSame('09:50', $times('panteras'));
    }

    public function test_the_rest_field_is_offered_instead_of_a_raw_interval(): void
    {
        $data = $this->setUpFecha();

        $this->actingAs($data['user'])
            ->get(route('tournaments.programming.edit', [$data['tournament'], 'round' => 5]))
            ->assertOk()
            ->assertSee('Descanso entre partidos')
            ->assertSee('rows['.$data['sub13']->id.'-0][rest]', false)
            ->assertDontSee('Minutos entre partidos');
    }

    public function test_a_row_without_a_start_time_only_gets_the_day(): void
    {
        $data = $this->setUpFecha();
        $planner = app(MatchProgrammingPlannerService::class);

        $rows = $planner->rows($planner->candidates($data['tournament'], 5, false));
        $plan = $planner->plan($rows, [$data['sub13']->id.'-0' => ['date' => '2026-09-12', 'venue_id' => $data['venue']->id, 'start' => '']]);

        $this->assertSame('2026-09-12 00:00:00', $plan[$data['matches']['tigres']->id]['at']->format('Y-m-d H:i:s'));
        $this->assertArrayNotHasKey($data['matches']['panteras']->id, $plan);
    }

    public function test_rows_on_different_venues_do_not_stack(): void
    {
        $data = $this->setUpFecha();
        $idolos = $this->makeSchedulingVenue($data['user'], 'Cancha Los Ídolos');
        $planner = app(MatchProgrammingPlannerService::class);

        $rows = $planner->rows($planner->candidates($data['tournament'], 5, false));
        $config = $this->previewPayload($data, [
            'rows' => [$data['sub15']->id.'-0' => ['venue_id' => $idolos->id]],
        ])['rows'];
        $plan = $planner->plan($rows, $config);

        $this->assertSame('07:30', $plan[$data['matches']['panteras']->id]['at']->format('H:i'));
    }

    public function test_candidates_leave_out_matches_that_already_have_a_day_unless_overwriting(): void
    {
        $data = $this->setUpFecha();
        $data['matches']['osos']->update(['scheduled_at' => '2026-09-05 09:00:00']);
        $planner = app(MatchProgrammingPlannerService::class);

        $this->assertCount(2, $planner->candidates($data['tournament'], 5, false));
        $this->assertCount(3, $planner->candidates($data['tournament'], 5, true));
    }

    // ── Pantallas ────────────────────────────────────────────────────────

    public function test_the_tournament_page_links_to_the_tool(): void
    {
        $data = $this->setUpFecha();

        $this->actingAs($data['user'])
            ->get(route('tournaments.show', $data['tournament']))
            ->assertOk()
            ->assertSee(route('tournaments.programming.edit', $data['tournament']), false)
            ->assertSee('Programar fecha');
    }

    public function test_the_tool_lists_the_pending_fechas_and_the_rows_of_the_chosen_one(): void
    {
        $data = $this->setUpFecha();

        $this->actingAs($data['user'])
            ->get(route('tournaments.programming.edit', $data['tournament']))
            ->assertOk()
            ->assertSee('Fecha 5')
            ->assertSee('Fecha 6')
            ->assertSee('Elige una fecha para empezar');

        $this->actingAs($data['user'])
            ->get(route('tournaments.programming.edit', [$data['tournament'], 'round' => 5]))
            ->assertOk()
            ->assertSee('Quinta fecha')
            ->assertSee('SUB-13')
            ->assertSee('SUB-15')
            ->assertSee('2 partidos')
            ->assertSee('rows['.$data['sub13']->id.'-0][date]', false);
    }

    public function test_the_tool_says_when_every_match_of_the_fecha_already_has_a_day(): void
    {
        $data = $this->setUpFecha();
        foreach (['tigres', 'osos', 'panteras'] as $key) {
            $data['matches'][$key]->update(['scheduled_at' => '2026-09-05 09:00:00']);
        }

        $this->actingAs($data['user'])
            ->get(route('tournaments.programming.edit', [$data['tournament'], 'round' => 5]))
            ->assertOk()
            ->assertSee('ya tienen día asignado');

        $this->actingAs($data['user'])
            ->get(route('tournaments.programming.edit', [$data['tournament'], 'round' => 5, 'overwrite' => 1]))
            ->assertOk()
            ->assertSee('rows['.$data['sub13']->id.'-0][date]', false);
    }

    public function test_another_organizer_cannot_use_the_tool(): void
    {
        $data = $this->setUpFecha();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('tournaments.programming.edit', $data['tournament']))->assertForbidden();
        $this->actingAs($stranger)->post(route('tournaments.programming.preview', $data['tournament']), $this->previewPayload($data))->assertForbidden();
        $this->actingAs($stranger)->post(route('tournaments.programming.store', $data['tournament']), ['round' => 5, 'matches' => []])->assertForbidden();
    }

    // ── Vista previa ─────────────────────────────────────────────────────

    public function test_the_preview_proposes_the_chained_times_without_saving_anything(): void
    {
        $data = $this->setUpFecha();

        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.preview', $data['tournament']), $this->previewPayload($data))
            ->assertOk()
            ->assertSee('Vista previa de la programación')
            ->assertSee('Sin cruces')
            ->assertSee('matches['.$data['matches']['osos']->id.'][time]', false)
            ->assertSee('value="08:30"', false)
            ->assertSee('value="09:30"', false);

        $this->assertNull($data['matches']['tigres']->fresh()->scheduled_at);
    }

    public function test_the_preview_needs_a_day_for_at_least_one_row(): void
    {
        $data = $this->setUpFecha();

        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.preview', $data['tournament']), ['round' => 5, 'rows' => [$data['sub13']->id.'-0' => ['date' => '']]])
            ->assertSessionHasErrors('rows');
    }

    public function test_a_start_time_without_a_day_is_rejected(): void
    {
        $data = $this->setUpFecha();

        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.preview', $data['tournament']), $this->previewPayload($data, [
                'rows' => [$data['sub15']->id.'-0' => ['date' => '']],
            ]))
            ->assertSessionHasErrors('rows.'.$data['sub15']->id.'-0.date');
    }

    public function test_the_preview_flags_clashes_with_the_existing_schedule(): void
    {
        $data = $this->setUpFecha();
        // Tigres already play that Saturday, in fecha 4.
        $this->makeSchedulingMatch($data['tournament'], $data['sub13'], 'Tigres', 'Pumas', 4, '2026-09-12 15:00:00');

        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.preview', $data['tournament']), $this->previewPayload($data))
            ->assertOk()
            ->assertSee('corrígelo para poder guardar')
            ->assertSee('TIGRES ya juega ese día');
    }

    // ── Guardar ──────────────────────────────────────────────────────────

    public function test_saving_applies_the_reviewed_slots_and_leaves_everything_else_alone(): void
    {
        $data = $this->setUpFecha();
        $m = $data['matches'];

        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.store', $data['tournament']), [
                'round' => 5,
                'matches' => [
                    $m['tigres']->id => ['date' => '2026-09-12', 'time' => '07:30', 'venue_id' => $data['venue']->id],
                    $m['osos']->id => ['date' => '2026-09-12', 'time' => '', 'venue_id' => ''],
                    $m['panteras']->id => ['date' => '', 'time' => '09:30', 'venue_id' => $data['venue']->id],
                ],
            ])
            ->assertRedirect(route('tournaments.programming.edit', [$data['tournament'], 'round' => 5]))
            ->assertSessionHas('status');

        $this->assertSame('2026-09-12 07:30:00', $m['tigres']->fresh()->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame($data['venue']->id, $m['tigres']->fresh()->venue_id);

        $osos = $m['osos']->fresh();
        $this->assertSame('2026-09-12', $osos->scheduled_at->toDateString());
        $this->assertFalse($osos->hasKickoffTime());
        $this->assertNull($osos->venue_id);

        // A row left without a day is skipped, never cleared.
        $this->assertNull($m['panteras']->fresh()->scheduled_at);
        $this->assertNull($m['fecha6']->fresh()->scheduled_at);
    }

    public function test_saving_brings_postponed_matches_back_to_scheduled(): void
    {
        $data = $this->setUpFecha();
        $data['matches']['tigres']->update(['status' => MatchStatus::Postponed]);

        $this->actingAs($data['user'])->post(route('tournaments.programming.store', $data['tournament']), [
            'round' => 5,
            'matches' => [$data['matches']['tigres']->id => ['date' => '2026-09-12', 'time' => '07:30']],
        ]);

        $this->assertSame(MatchStatus::Scheduled, $data['matches']['tigres']->fresh()->status);
    }

    public function test_saving_a_clash_brings_the_preview_back_and_saves_nothing(): void
    {
        $data = $this->setUpFecha();
        $m = $data['matches'];

        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.store', $data['tournament']), [
                'round' => 5,
                'matches' => [
                    // Same venue, overlapping times.
                    $m['tigres']->id => ['date' => '2026-09-12', 'time' => '07:30', 'venue_id' => $data['venue']->id],
                    $m['panteras']->id => ['date' => '2026-09-12', 'time' => '08:00', 'venue_id' => $data['venue']->id],
                ],
            ])
            ->assertOk()
            ->assertSee('Vista previa de la programación')
            ->assertSee('CANCHA BOSCÁN está ocupada');

        $this->assertNull($m['tigres']->fresh()->scheduled_at);
        $this->assertNull($m['panteras']->fresh()->scheduled_at);
    }

    public function test_saving_ignores_matches_of_another_tournament(): void
    {
        $data = $this->setUpFecha();
        ['tournament' => $otherTournament, 'user' => $otherUser] = $this->makeSchedulingTournament();
        $otherCategory = $this->makeSchedulingCategory($otherTournament, 'Sub-13', 2013);
        $foreign = $this->makeSchedulingMatch($otherTournament, $otherCategory, 'Ajeno', 'Extraño', 5);

        $this->actingAs($data['user'])->post(route('tournaments.programming.store', $data['tournament']), [
            'round' => 5,
            'matches' => [
                $data['matches']['tigres']->id => ['date' => '2026-09-12', 'time' => '07:30'],
                $foreign->id => ['date' => '2026-09-12', 'time' => '07:30'],
            ],
        ]);

        $this->assertNotNull($data['matches']['tigres']->fresh()->scheduled_at);
        $this->assertNull($foreign->fresh()->scheduled_at);
        $this->assertNotSame($otherUser->id, $data['user']->id);
    }

    public function test_a_venue_of_another_organizer_is_rejected(): void
    {
        $data = $this->setUpFecha();
        $foreign = Venue::factory()->create();

        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.store', $data['tournament']), [
                'round' => 5,
                'matches' => [$data['matches']['tigres']->id => ['date' => '2026-09-12', 'time' => '07:30', 'venue_id' => $foreign->id]],
            ])
            ->assertSessionHasErrors('matches.'.$data['matches']['tigres']->id.'.venue_id');
    }
}
