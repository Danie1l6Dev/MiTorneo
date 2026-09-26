<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchStatus;
use App\Models\Category;
use App\Models\Group;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Models\Venue;
use App\Services\MatchProgrammingPlannerService;
use App\Services\MatchProgrammingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Tournaments\Concerns\MakesSchedulableMatches;
use Tests\TestCase;

/**
 * "Programar fecha": assigning days, hours and canchas to the pending matches
 * of ONE category in ONE fecha (TournamentProgrammingController +
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
     * What the page posts to preview a category: the fecha, the category and
     * the four fields.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function previewPayload(array $data, array $config = [], ?Category $category = null): array
    {
        return [
            'round' => 5,
            'category' => ($category ?? $data['sub13'])->id,
            'config' => [...['date' => '2026-09-12', 'venue_id' => $data['venue']->id, 'start' => '07:30', 'rest' => ''], ...$config],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storePayload(array $data, array $matches, ?Category $category = null): array
    {
        return ['round' => 5, 'category' => ($category ?? $data['sub13'])->id, 'matches' => $matches];
    }

    // ── Planner ──────────────────────────────────────────────────────────

    public function test_the_planner_chains_one_categorys_kickoffs_by_its_duration(): void
    {
        $data = $this->setUpFecha();
        $planner = app(MatchProgrammingPlannerService::class);
        $matches = $planner->candidates($data['tournament'], 5, false)->where('category_id', $data['sub13']->id);

        $plan = $planner->planCategory($matches, ['date' => '2026-09-12', 'venue_id' => $data['venue']->id, 'start' => '07:30']);

        $this->assertSame('07:30', $plan[$data['matches']['tigres']->id]['at']->format('H:i'));
        $this->assertSame('08:30', $plan[$data['matches']['osos']->id]['at']->format('H:i'));
        $this->assertCount(2, $plan);
    }

    public function test_kickoffs_are_spaced_by_the_category_duration_plus_the_optional_rest(): void
    {
        $data = $this->setUpFecha();
        $planner = app(MatchProgrammingPlannerService::class);
        $matches = $planner->candidates($data['tournament'], 5, false)->where('category_id', $data['sub13']->id);

        $plan = $planner->planCategory($matches, ['date' => '2026-09-12', 'start' => '07:30', 'rest' => 10]);

        // A 60-minute category: the next match starts 60 + 10 minutes later.
        $this->assertSame('07:30', $plan[$data['matches']['tigres']->id]['at']->format('H:i'));
        $this->assertSame('08:40', $plan[$data['matches']['osos']->id]['at']->format('H:i'));
    }

    public function test_a_category_with_groups_is_chained_group_after_group(): void
    {
        $data = $this->setUpFecha();
        $groupA = Group::factory()->for($data['tournament'])->for($data['sub13'])->create(['name' => 'Grupo A']);
        $groupB = Group::factory()->for($data['tournament'])->for($data['sub13'])->create(['name' => 'Grupo B']);
        $data['matches']['tigres']->update(['group_id' => $groupA->id]);
        $data['matches']['osos']->update(['group_id' => $groupB->id]);
        $planner = app(MatchProgrammingPlannerService::class);
        $matches = $planner->candidates($data['tournament'], 5, false)->where('category_id', $data['sub13']->id);

        $plan = $planner->planCategory($matches, ['date' => '2026-09-12', 'start' => '07:30']);

        // Both groups share the cancha and the day, so the second starts when the first ends --
        // even with no cancha chosen.
        $this->assertSame('07:30', $plan[$data['matches']['tigres']->id]['at']->format('H:i'));
        $this->assertSame('08:30', $plan[$data['matches']['osos']->id]['at']->format('H:i'));
    }

    public function test_without_a_start_time_the_matches_only_get_the_day(): void
    {
        $data = $this->setUpFecha();
        $planner = app(MatchProgrammingPlannerService::class);
        $matches = $planner->candidates($data['tournament'], 5, false)->where('category_id', $data['sub13']->id);

        $plan = $planner->planCategory($matches, ['date' => '2026-09-12', 'venue_id' => $data['venue']->id]);

        $this->assertSame('2026-09-12 00:00:00', $plan[$data['matches']['tigres']->id]['at']->format('Y-m-d H:i:s'));
    }

    public function test_without_a_day_nothing_is_proposed(): void
    {
        $data = $this->setUpFecha();
        $planner = app(MatchProgrammingPlannerService::class);
        $matches = $planner->candidates($data['tournament'], 5, false)->where('category_id', $data['sub13']->id);

        $this->assertSame([], $planner->planCategory($matches, ['start' => '07:30']));
    }

    public function test_candidates_can_be_limited_to_one_category(): void
    {
        $data = $this->setUpFecha();
        $planner = app(MatchProgrammingPlannerService::class);

        $this->assertSame(
            [$data['matches']['tigres']->id, $data['matches']['osos']->id],
            $planner->candidates($data['tournament'], 5, false, $data['sub13'])->pluck('id')->sort()->values()->all(),
        );
        $this->assertSame([$data['matches']['panteras']->id], $planner->candidates($data['tournament'], 5, false, $data['sub15'])->pluck('id')->all());
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

    public function test_the_page_gets_every_fecha_with_its_categories_and_matches_in_one_go(): void
    {
        $data = $this->setUpFecha();

        $catalog = $this->actingAs($data['user'])
            ->get(route('tournaments.programming.edit', $data['tournament']))
            ->assertOk()
            ->viewData('catalog');

        $this->assertSame([5, 6], array_column($catalog, 'number'));
        $this->assertSame('Quinta fecha', $catalog[0]['title']);
        // Youngest category first.
        $this->assertSame(['SUB-13', 'SUB-15'], array_column($catalog[0]['categories'], 'name'));
        $this->assertSame(['SUB-13'], array_column($catalog[1]['categories'], 'name'));

        $sub13 = $catalog[0]['categories'][0];
        $this->assertSame($data['sub13']->id, $sub13['id']);
        $this->assertSame(['TIGRES', 'OSOS'], array_column($sub13['matches'], 'home'));
        $this->assertSame(['LEONES', 'LOBOS'], array_column($sub13['matches'], 'away'));
        $this->assertSame(['total' => 2, 'ready' => 0], ['total' => $sub13['total'], 'ready' => $sub13['ready']]);
        $this->assertSame([false, false], array_column($sub13['matches'], 'has_day'));
    }

    public function test_the_catalog_counts_played_matches_as_ready_and_flags_matches_with_a_day(): void
    {
        $data = $this->setUpFecha();
        $data['matches']['tigres']->update(['scheduled_at' => '2026-09-05 09:00:00']);
        // Played but never given a date: ready all the same. Cancelled: not part of the progress.
        $this->makeSchedulingMatch($data['tournament'], $data['sub13'], 'Pumas', 'Cóndores', 5, status: MatchStatus::Finished);
        $this->makeSchedulingMatch($data['tournament'], $data['sub13'], 'Zorros', 'Gatos', 5, status: MatchStatus::Cancelled);

        $catalog = app(MatchProgrammingReportService::class)->programmingCatalog($data['tournament']);
        $fecha5 = $catalog[0];
        $sub13 = $fecha5['categories'][0];

        // Fecha 5: tigres (dated), osos, panteras pending + the played one; the cancelled doesn't count.
        $this->assertSame(['total' => 4, 'ready' => 2], ['total' => $fecha5['total'], 'ready' => $fecha5['ready']]);
        $this->assertSame(['total' => 3, 'ready' => 2], ['total' => $sub13['total'], 'ready' => $sub13['ready']]);
        // The played match isn't pending, so it isn't listed -- the dated one is, flagged.
        $this->assertSame(['TIGRES', 'OSOS'], array_column($sub13['matches'], 'home'));
        $this->assertSame([true, false], array_column($sub13['matches'], 'has_day'));
    }

    public function test_a_fecha_whose_matches_were_all_played_is_not_offered(): void
    {
        $data = $this->setUpFecha();
        $this->makeSchedulingMatch($data['tournament'], $data['sub13'], 'Pumas', 'Cóndores', 7, status: MatchStatus::Finished);

        $report = app(MatchProgrammingReportService::class);

        $this->assertSame([5, 6], array_column($report->programmingCatalog($data['tournament']), 'number'));
        $this->assertSame(['total' => 1, 'ready' => 1], $report->roundSummaries($data['tournament'])[7]);
    }

    public function test_the_catalog_only_holds_this_tournaments_matches(): void
    {
        $data = $this->setUpFecha();
        ['tournament' => $other] = $this->makeSchedulingTournament();
        $this->makeSchedulingMatch($other, $this->makeSchedulingCategory($other, 'Sub-9', 2017), 'Ajeno', 'Extraño', 5);

        $catalog = app(MatchProgrammingReportService::class)->programmingCatalog($data['tournament']);

        $this->assertSame(['SUB-13', 'SUB-15'], array_column($catalog[0]['categories'], 'name'));
        $this->assertStringNotContainsString('AJENO', json_encode($catalog));
    }

    public function test_the_page_starts_on_the_selection_in_the_address(): void
    {
        $data = $this->setUpFecha();
        $url = fn (array $query) => route('tournaments.programming.edit', [$data['tournament'], ...$query]);

        $this->assertSame(
            ['round' => 5, 'category' => $data['sub13']->id, 'overwrite' => true],
            $this->actingAs($data['user'])->get($url(['round' => 5, 'category' => $data['sub13']->id, 'overwrite' => 1]))->viewData('initial'),
        );
        $this->assertSame(
            ['round' => null, 'category' => null, 'overwrite' => false],
            $this->actingAs($data['user'])->get($url([]))->viewData('initial'),
        );
        // A category that isn't in that fecha, or a fecha with nothing pending, is ignored.
        $this->assertSame(
            ['round' => 6, 'category' => null, 'overwrite' => false],
            $this->actingAs($data['user'])->get($url(['round' => 6, 'category' => $data['sub15']->id]))->viewData('initial'),
        );
        $this->assertSame(
            ['round' => null, 'category' => null, 'overwrite' => false],
            $this->actingAs($data['user'])->get($url(['round' => 9]))->viewData('initial'),
        );
    }

    public function test_the_page_filters_in_the_browser_and_only_asks_the_server_for_the_proposal(): void
    {
        $data = $this->setUpFecha();

        $this->actingAs($data['user'])
            ->get(route('tournaments.programming.edit', $data['tournament']))
            ->assertOk()
            ->assertSee('x-data', false)
            ->assertSee('pickRound(item.number)', false)
            ->assertSee('pickCategory(item.id)', false)
            // Where the proposal comes from (the URL is JSON-escaped inside the page).
            ->assertSee(str_replace('/', '\\/', route('tournaments.programming.preview', $data['tournament'])), false)
            ->assertSee('x-ref="preview"', false)
            ->assertSee('config[date]', false)
            ->assertSee('config[venue_id]', false)
            ->assertSee('config[start]', false)
            ->assertSee('config[rest]', false)
            ->assertSee('Descanso entre partidos')
            ->assertSee('Elige una fecha para empezar')
            ->assertSee('Elige una categoría para ver sus partidos');
    }

    public function test_a_tournament_with_nothing_pending_says_so(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();

        $this->actingAs($user)
            ->get(route('tournaments.programming.edit', $tournament))
            ->assertOk()
            ->assertSee('No hay partidos pendientes por programar');
    }

    public function test_another_organizer_cannot_use_the_tool(): void
    {
        $data = $this->setUpFecha();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('tournaments.programming.edit', $data['tournament']))->assertForbidden();
        $this->actingAs($stranger)->post(route('tournaments.programming.preview', $data['tournament']), $this->previewPayload($data))->assertForbidden();
        $this->actingAs($stranger)->post(route('tournaments.programming.store', $data['tournament']), $this->storePayload($data, []))->assertForbidden();
    }

    // ── Vista previa en vivo ─────────────────────────────────────────────

    public function test_the_preview_is_a_fragment_with_the_chained_times_and_saves_nothing(): void
    {
        $data = $this->setUpFecha();

        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.preview', $data['tournament']), $this->previewPayload($data))
            ->assertOk()
            ->assertSee('Sin cruces')
            ->assertSee('matches['.$data['matches']['osos']->id.'][time]', false)
            ->assertSee('value="07:30"', false)
            ->assertSee('value="08:30"', false)
            ->assertSee('TIGRES')
            ->assertDontSee('PANTERAS')
            ->assertDontSee('<html', false);

        $this->assertNull($data['matches']['tigres']->fresh()->scheduled_at);
    }

    public function test_the_preview_says_from_when_a_cancha_is_free_and_offers_to_start_there(): void
    {
        $data = $this->setUpFecha();
        // Another category already has the cancha that Saturday from 7:30 to 8:30 (60-minute category).
        $this->makeSchedulingMatch($data['tournament'], $data['sub15'], 'Halcones', 'Cóndores', 4, '2026-09-12 07:30:00', $data['venue']);

        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.preview', $data['tournament']), $this->previewPayload($data, ['start' => '']))
            ->assertOk()
            ->assertSee('queda libre desde las 8:30 AM')
            ->assertSee('Empezar a las 8:30 AM')
            ->assertSee("useStart('08:30')", false);

        // Starting at or after that time: nothing to suggest.
        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.preview', $data['tournament']), $this->previewPayload($data, ['start' => '08:30']))
            ->assertOk()
            ->assertDontSee('queda libre desde');

        // No cancha chosen: nothing to say either.
        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.preview', $data['tournament']), $this->previewPayload($data, ['venue_id' => '', 'start' => '']))
            ->assertOk()
            ->assertDontSee('queda libre desde');
    }

    public function test_the_hint_ignores_the_matches_about_to_be_rescheduled(): void
    {
        $data = $this->setUpFecha();
        // The category's own match already sits at 7:30 on that cancha: re-planning it must not "occupy" the cancha.
        $data['matches']['tigres']->update(['scheduled_at' => '2026-09-12 07:30:00', 'venue_id' => $data['venue']->id]);

        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.preview', $data['tournament']), [...$this->previewPayload($data, ['start' => '']), 'overwrite' => 1])
            ->assertOk()
            ->assertDontSee('queda libre desde');
    }

    public function test_the_preview_shows_the_category_matches_blank_until_a_day_is_given(): void
    {
        $data = $this->setUpFecha();

        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.preview', $data['tournament']), $this->previewPayload($data, ['date' => '']))
            ->assertOk()
            ->assertSee('Elige el día para ver la propuesta')
            ->assertSee('TIGRES')
            ->assertSee('OSOS');
    }

    public function test_a_half_typed_field_is_never_an_error(): void
    {
        $data = $this->setUpFecha();

        // A time without a day, an impossible date, garbage in the rest, a
        // venue that isn't the organizer's: all just "nothing to propose yet".
        $foreign = Venue::factory()->create();

        foreach ([
            ['date' => '', 'start' => '07:30'],
            ['date' => '2026-02-31'],
            ['date' => '2026-09-12', 'start' => '99:99', 'rest' => 'abc'],
            ['date' => '2026-09-12', 'venue_id' => $foreign->id],
        ] as $config) {
            $this->actingAs($data['user'])
                ->post(route('tournaments.programming.preview', $data['tournament']), $this->previewPayload($data, $config))
                ->assertOk();
        }
    }

    public function test_a_venue_of_another_organizer_is_ignored_by_the_preview(): void
    {
        $data = $this->setUpFecha();
        $foreign = Venue::factory()->create(['name' => 'Cancha Ajena']);

        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.preview', $data['tournament']), $this->previewPayload($data, ['venue_id' => $foreign->id]))
            ->assertOk()
            ->assertDontSee('selected', false)
            ->assertDontSee('CANCHA AJENA');
    }

    public function test_the_preview_flags_clashes_and_blocks_saving(): void
    {
        $data = $this->setUpFecha();
        // Tigres already play that Saturday, in fecha 4.
        $this->makeSchedulingMatch($data['tournament'], $data['sub13'], 'Tigres', 'Pumas', 4, '2026-09-12 15:00:00');

        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.preview', $data['tournament']), $this->previewPayload($data))
            ->assertOk()
            ->assertSee('corrígelo para poder guardar')
            ->assertSee('TIGRES ya juega ese día')
            ->assertSee('disabled', false);
    }

    public function test_the_save_button_is_offered_only_when_there_is_a_proposal_without_clashes(): void
    {
        $data = $this->setUpFecha();

        $withoutDay = $this->actingAs($data['user'])
            ->post(route('tournaments.programming.preview', $data['tournament']), $this->previewPayload($data, ['date' => '']))
            ->getContent();
        $clean = $this->actingAs($data['user'])
            ->post(route('tournaments.programming.preview', $data['tournament']), $this->previewPayload($data))
            ->getContent();

        // Flux renders a disabled button with disabled="disabled".
        $this->assertStringContainsString('disabled="disabled"', $withoutDay);
        $this->assertStringNotContainsString('disabled="disabled"', $clean);
    }

    public function test_a_match_adjusted_by_hand_keeps_its_values_and_is_checked(): void
    {
        $data = $this->setUpFecha();
        $m = $data['matches'];

        // The planner would have put osos at 08:30; the user moved it to 07:45, on
        // the same cancha as tigres (07:30 to 08:30): a clash, and the value stays.
        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.preview', $data['tournament']), [
                ...$this->previewPayload($data),
                'mode' => 'matches',
                'matches' => [
                    $m['tigres']->id => ['date' => '2026-09-12', 'time' => '07:30', 'venue_id' => $data['venue']->id],
                    $m['osos']->id => ['date' => '2026-09-12', 'time' => '07:45', 'venue_id' => $data['venue']->id],
                ],
            ])
            ->assertOk()
            ->assertSee('value="07:45"', false)
            ->assertDontSee('value="08:30"', false)
            ->assertSee('Hay 2 partidos con cruces')
            ->assertSee('está ocupada')
            ->assertSee('disabled="disabled"', false);
    }

    public function test_a_hand_adjusted_panel_without_clashes_offers_saving(): void
    {
        $data = $this->setUpFecha();
        $m = $data['matches'];

        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.preview', $data['tournament']), [
                ...$this->previewPayload($data),
                'mode' => 'matches',
                'matches' => [
                    $m['tigres']->id => ['date' => '2026-09-12', 'time' => '07:30', 'venue_id' => $data['venue']->id],
                    $m['osos']->id => ['date' => '2026-09-12', 'time' => '10:00', 'venue_id' => $data['venue']->id],
                ],
            ])
            ->assertOk()
            ->assertSee('Sin cruces')
            ->assertSee('value="10:00"', false)
            ->assertDontSee('disabled="disabled"', false);
    }

    public function test_the_hand_adjusted_mode_ignores_other_categories_and_broken_values(): void
    {
        $data = $this->setUpFecha();
        $m = $data['matches'];

        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.preview', $data['tournament']), [
                ...$this->previewPayload($data),
                'mode' => 'matches',
                'matches' => [
                    // Not in the chosen category: ignored.
                    $m['panteras']->id => ['date' => '2026-09-12', 'time' => '07:30'],
                    // An impossible day: no slot for it, and no error.
                    $m['tigres']->id => ['date' => '2026-02-31', 'time' => '07:30'],
                    // A broken time: the day is kept, the time is "por definir".
                    $m['osos']->id => ['date' => '2026-09-12', 'time' => '99:99'],
                    'x' => ['date' => '2026-09-12'],
                ],
            ])
            ->assertOk()
            ->assertDontSee('PANTERAS')
            ->assertSee('value="2026-09-12"', false);
    }

    public function test_the_page_re_checks_by_hand_adjustments_through_the_same_panel(): void
    {
        $data = $this->setUpFecha();

        $this->actingAs($data['user'])
            ->get(route('tournaments.programming.edit', $data['tournament']))
            ->assertOk()
            ->assertSee('queueMatches()', false)
            ->assertSee("refresh('matches')", false)
            ->assertSee("body.set('mode', mode)", false);
    }

    // ── Guardar ──────────────────────────────────────────────────────────

    public function test_saving_applies_the_reviewed_slots_and_leaves_everything_else_alone(): void
    {
        $data = $this->setUpFecha();
        $m = $data['matches'];

        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.store', $data['tournament']), $this->storePayload($data, [
                $m['tigres']->id => ['date' => '2026-09-12', 'time' => '07:30', 'venue_id' => $data['venue']->id],
                $m['osos']->id => ['date' => '2026-09-12', 'time' => '', 'venue_id' => ''],
            ]))
            ->assertRedirect(route('tournaments.programming.edit', [$data['tournament'], 'round' => 5, 'category' => $data['sub13']->id]))
            ->assertSessionHas('status');

        $this->assertSame('2026-09-12 07:30:00', $m['tigres']->fresh()->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame($data['venue']->id, $m['tigres']->fresh()->venue_id);

        $osos = $m['osos']->fresh();
        $this->assertSame('2026-09-12', $osos->scheduled_at->toDateString());
        $this->assertFalse($osos->hasKickoffTime());
        $this->assertNull($osos->venue_id);

        // Another category and another fecha are untouched.
        $this->assertNull($m['panteras']->fresh()->scheduled_at);
        $this->assertNull($m['fecha6']->fresh()->scheduled_at);
    }

    public function test_a_match_left_without_a_day_is_skipped_not_cleared(): void
    {
        $data = $this->setUpFecha();
        $m = $data['matches'];
        $m['osos']->update(['scheduled_at' => '2026-09-05 09:00:00']);

        $this->actingAs($data['user'])->post(route('tournaments.programming.store', $data['tournament']), $this->storePayload($data, [
            $m['tigres']->id => ['date' => '2026-09-12', 'time' => '07:30'],
            $m['osos']->id => ['date' => '', 'time' => ''],
        ]));

        $this->assertSame('2026-09-05 09:00:00', $m['osos']->fresh()->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_saving_brings_postponed_matches_back_to_scheduled(): void
    {
        $data = $this->setUpFecha();
        $data['matches']['tigres']->update(['status' => MatchStatus::Postponed]);

        $this->actingAs($data['user'])->post(route('tournaments.programming.store', $data['tournament']), $this->storePayload($data, [
            $data['matches']['tigres']->id => ['date' => '2026-09-12', 'time' => '07:30'],
        ]));

        $this->assertSame(MatchStatus::Scheduled, $data['matches']['tigres']->fresh()->status);
    }

    public function test_saving_a_clash_goes_back_with_the_reasons_and_the_fields_and_saves_nothing(): void
    {
        $data = $this->setUpFecha();
        $m = $data['matches'];

        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.store', $data['tournament']), [
                ...$this->storePayload($data, [
                    // Same venue, overlapping times.
                    $m['tigres']->id => ['date' => '2026-09-12', 'time' => '07:30', 'venue_id' => $data['venue']->id],
                    $m['osos']->id => ['date' => '2026-09-12', 'time' => '08:00', 'venue_id' => $data['venue']->id],
                ]),
                'config' => ['date' => '2026-09-12', 'start' => '07:30'],
            ])
            ->assertRedirect(route('tournaments.programming.edit', [$data['tournament'], 'round' => 5, 'category' => $data['sub13']->id]))
            ->assertSessionHasErrors('schedule')
            ->assertSessionHasInput('config.date', '2026-09-12');

        $this->assertStringContainsString('CANCHA BOSCÁN está ocupada', session('errors')->get('schedule')[0]);
        $this->assertNull($m['tigres']->fresh()->scheduled_at);
        $this->assertNull($m['osos']->fresh()->scheduled_at);
    }

    public function test_the_page_shows_the_reasons_and_restores_the_fields_after_a_failed_save(): void
    {
        $data = $this->setUpFecha();
        $m = $data['matches'];

        $this->actingAs($data['user'])
            ->from(route('tournaments.programming.edit', [$data['tournament'], 'round' => 5, 'category' => $data['sub13']->id]))
            ->post(route('tournaments.programming.store', $data['tournament']), [
                ...$this->storePayload($data, [
                    $m['tigres']->id => ['date' => '2026-09-12', 'time' => '07:30', 'venue_id' => $data['venue']->id],
                    $m['osos']->id => ['date' => '2026-09-12', 'time' => '08:00', 'venue_id' => $data['venue']->id],
                ]),
                'config' => ['date' => '2026-09-12', 'start' => '07:30'],
            ]);

        $this->actingAs($data['user'])
            ->get(route('tournaments.programming.edit', [$data['tournament'], 'round' => 5, 'category' => $data['sub13']->id]))
            ->assertOk()
            ->assertSee('Revisa los datos ingresados')
            ->assertSee('está ocupada')
            ->assertSee('value="2026-09-12"', false);
    }

    public function test_saving_ignores_matches_of_another_tournament(): void
    {
        $data = $this->setUpFecha();
        ['tournament' => $otherTournament] = $this->makeSchedulingTournament();
        $otherCategory = $this->makeSchedulingCategory($otherTournament, 'Sub-13', 2013);
        $foreign = $this->makeSchedulingMatch($otherTournament, $otherCategory, 'Ajeno', 'Extraño', 5);

        $this->actingAs($data['user'])->post(route('tournaments.programming.store', $data['tournament']), $this->storePayload($data, [
            $data['matches']['tigres']->id => ['date' => '2026-09-12', 'time' => '07:30'],
            $foreign->id => ['date' => '2026-09-12', 'time' => '07:30'],
        ]));

        $this->assertNotNull($data['matches']['tigres']->fresh()->scheduled_at);
        $this->assertNull($foreign->fresh()->scheduled_at);
    }

    public function test_a_venue_of_another_organizer_is_rejected_when_saving(): void
    {
        $data = $this->setUpFecha();
        $foreign = Venue::factory()->create();

        $this->actingAs($data['user'])
            ->post(route('tournaments.programming.store', $data['tournament']), $this->storePayload($data, [
                $data['matches']['tigres']->id => ['date' => '2026-09-12', 'time' => '07:30', 'venue_id' => $foreign->id],
            ]))
            ->assertSessionHasErrors('matches.'.$data['matches']['tigres']->id.'.venue_id');
    }
}
