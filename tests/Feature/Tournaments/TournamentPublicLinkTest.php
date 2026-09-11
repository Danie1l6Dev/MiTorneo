<?php

namespace Tests\Feature\Tournaments;

use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public portal's tournament identifier: a stable, unique slug -- never
 * the raw internal id -- that keeps working even after the tournament is
 * renamed (Tournament::generateUniqueSlug(), TournamentController::store()),
 * plus the admin-side "share this link" widget
 * (pages/tournaments/show.blade.php, x-ui.copy-link).
 */
class TournamentPublicLinkTest extends TestCase
{
    use RefreshDatabase;

    // ── Slug único ───────────────────────────────────────────────────────

    public function test_two_tournaments_created_with_the_same_name_get_different_unique_slugs(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post(route('tournaments.store'), ['name' => 'Copa Verano', 'status' => 'active']);
        $this->post(route('tournaments.store'), ['name' => 'Copa Verano', 'status' => 'active']);

        $tournaments = Tournament::query()->where('name', 'Copa Verano')->orderBy('id')->get();

        $this->assertCount(2, $tournaments);
        $this->assertNotSame($tournaments[0]->slug, $tournaments[1]->slug);
        $this->assertSame('copa-verano', $tournaments[0]->slug);
        $this->assertSame('copa-verano-2', $tournaments[1]->slug);
    }

    public function test_the_slug_column_enforces_uniqueness_at_the_database_level(): void
    {
        Tournament::factory()->create(['slug' => 'torneo-unico']);

        $this->expectException(QueryException::class);

        Tournament::factory()->create(['slug' => 'torneo-unico']);
    }

    // ── Estabilidad: renombrar el torneo no debe romper el enlace ────────

    public function test_renaming_a_tournament_does_not_change_its_public_slug(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create([
            'name' => 'Nombre Original',
            'slug' => 'nombre-original',
        ]);

        $this->actingAs($user)->put(route('tournaments.update', $tournament), [
            'name' => 'Nombre Cambiado',
            'status' => $tournament->status->value,
        ]);

        $tournament->refresh();
        $this->assertSame('nombre-original', $tournament->slug);
        $this->assertSame('Nombre Cambiado', $tournament->name);

        // The link shared before the rename must keep working afterward.
        $this->get('/public/torneos/nombre-original')
            ->assertOk()
            ->assertSee('Nombre Cambiado');
    }

    // ── Acceso mediante slug (nunca el id interno) ───────────────────────

    public function test_a_guest_can_access_a_tournament_through_its_slug_url(): void
    {
        Tournament::factory()->create(['name' => 'Torneo Abierto', 'slug' => 'torneo-abierto']);

        $this->get('/public/torneos/torneo-abierto')
            ->assertOk()
            ->assertSee('Torneo Abierto');
    }

    public function test_the_public_route_does_not_resolve_by_the_raw_internal_id(): void
    {
        $tournament = Tournament::factory()->create(['slug' => 'torneo-con-slug-propio']);

        // Route::get('public/torneos/{tournament:slug}', ...) binds by slug
        // only -- the numeric id must not also work as a shortcut, or the
        // slug would be pointless as the "don't expose a predictable
        // internal id" identifier.
        $this->get('/public/torneos/'.$tournament->id)->assertNotFound();
    }

    // ── Torneo inexistente ───────────────────────────────────────────────

    public function test_visiting_an_unknown_slug_returns_a_404(): void
    {
        $this->get('/public/torneos/este-slug-no-existe-2026')->assertNotFound();
    }

    // ── Acceso público sin autenticación ──────────────────────────────────

    public function test_the_public_tournament_page_is_reachable_without_authentication(): void
    {
        $tournament = Tournament::factory()->create(['slug' => 'torneo-publico']);

        $this->get(route('public.tournaments.show', $tournament))->assertOk();
    }

    // ── Enlace mostrado (y copiable/abrible) en administración ───────────

    public function test_the_admin_tournament_page_shows_the_copyable_public_link(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create(['slug' => 'torneo-compartible']);

        $this->actingAs($user)
            ->get(route('tournaments.show', $tournament))
            ->assertOk()
            ->assertSee(__('Enlace público'))
            // The full shareable URL is rendered as the readonly input's value.
            ->assertSee(route('public.tournaments.show', $tournament), false);
    }

    public function test_the_admin_tournament_page_offers_an_open_action_for_the_public_link(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('tournaments.show', $tournament))
            ->assertOk()
            ->assertSee(__('Abrir'));
    }

    public function test_a_user_cannot_see_another_users_tournament_share_link(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $tournament = Tournament::factory()->for($owner)->create();

        $this->actingAs($otherUser)
            ->get(route('tournaments.show', $tournament))
            ->assertForbidden();
    }
}
