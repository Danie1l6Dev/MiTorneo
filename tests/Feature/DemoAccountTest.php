<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DemoResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_login_is_404_when_disabled(): void
    {
        config(['demo.enabled' => false]);

        $this->post(route('demo.login'))->assertNotFound();
    }

    public function test_demo_login_signs_in_the_demo_user_when_enabled(): void
    {
        config(['demo.enabled' => true]);
        $demo = (new DemoResetService)->reset();

        $this->post(route('demo.login'))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($demo);
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_login_page_shows_the_demo_button_only_when_enabled(): void
    {
        config(['demo.enabled' => false]);
        $this->get(route('login'))->assertDontSee('demo-login-button');

        config(['demo.enabled' => true]);
        $this->get(route('login'))->assertSee('demo-login-button');
    }

    public function test_demo_user_cannot_reach_credential_pages(): void
    {
        $demo = (new DemoResetService)->reset();

        $this->actingAs($demo)->get(route('profile.edit'))->assertRedirect(route('dashboard'));
        $this->actingAs($demo)->get(route('appearance.edit'))->assertOk();
        $this->actingAs($demo)->post('user/two-factor-authentication')->assertForbidden();
    }

    public function test_regular_users_are_unaffected(): void
    {
        $this->actingAs(User::factory()->create())->get(route('profile.edit'))->assertOk();
    }

    public function test_demo_data_actually_shows_up_in_the_app(): void
    {
        $demo = (new DemoResetService)->reset();
        $tournament = $demo->tournaments()->where('slug', 'liga-profesional-2026-demo')->firstOrFail();
        $phase = $tournament->competitionPhases()->firstOrFail();

        $this->actingAs($demo)->get(route('categories.index'))->assertOk()->assertSee('PRIMERA DIVISIÓN');
        $this->actingAs($demo)->get(route('clubs.index'))->assertOk()->assertSee('REAL NORTE FC');
        $this->actingAs($demo)->get(route('tournaments.show', $tournament))->assertOk()->assertSee('PRIMERA DIVISIÓN');
        $this->actingAs($demo)->get(route('phases.show', $phase))->assertOk()->assertSee('REAL NORTE FC');
        $this->actingAs($demo)->get(route('sanctions.index'))->assertOk();
        $this->get(route('public.tournaments.show', $tournament))->assertOk()->assertSee('PRIMERA DIVISIÓN');
    }

    public function test_nobody_can_register_with_the_reserved_demo_email(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Intruso',
            'email' => 'Demo@MiTorneo.test',
            'password' => 'password-segura-123',
            'password_confirmation' => 'password-segura-123',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', ['email' => User::DEMO_EMAIL]);
    }
}
