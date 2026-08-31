<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_the_admin_area(): void
    {
        $response = $this->get(route('admin.dashboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_normal_users_cannot_access_the_admin_area(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.tournaments.index'))->assertForbidden();
    }

    public function test_admins_can_view_the_admin_dashboard(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_admins_can_list_all_users(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->count(2)->create();

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertOk();
        $response->assertViewHas('users', fn ($users) => $users->count() === 3);
    }

    public function test_admins_can_toggle_a_users_active_status(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($admin)
            ->patch(route('admin.users.toggle-active', $user))
            ->assertRedirect();

        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_admins_cannot_deactivate_their_own_account(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $this->actingAs($admin)->patch(route('admin.users.toggle-active', $admin));

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_normal_users_cannot_toggle_active_status(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $other = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('admin.users.toggle-active', $other))
            ->assertForbidden();
    }

    public function test_admins_can_list_tournaments_from_every_user(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();

        Tournament::factory()->for($ownerA)->create();
        Tournament::factory()->for($ownerB)->create();

        $response = $this->actingAs($admin)->get(route('admin.tournaments.index'));

        $response->assertOk();
        $response->assertViewHas('tournaments', fn ($tournaments) => $tournaments->count() === 2);
    }
}
