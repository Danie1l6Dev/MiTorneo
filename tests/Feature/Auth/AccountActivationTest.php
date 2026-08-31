<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_users_cannot_log_in(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrorsIn('email');
        $this->assertGuest();
    }

    public function test_a_deactivated_user_is_logged_out_of_their_active_session(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user);
        $this->get(route('dashboard'))->assertOk();

        $user->is_active = false;
        $user->save();

        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
