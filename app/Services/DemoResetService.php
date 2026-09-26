<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Club;
use App\Models\Referee;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Resets the shared public-demo account back to its seeded state.
 *
 * Deliberately scoped to ONE user: every delete below is filtered by that
 * user's id and the account is verified to be the demo one first, so a bug or
 * a wrong argument can never wipe another organizer's data. Children (teams,
 * players, matches, events, sanctions, ...) go away through the
 * cascadeOnDelete foreign keys hanging off these four owner tables.
 */
class DemoResetService
{
    public function reset(): User
    {
        $demo = User::query()->where('email', User::DEMO_EMAIL)->first() ?? new User;

        // Created by this service, or already there from an old `db:seed` (whose
        // factory gave it the public password "password"). Either way, on every
        // reset: a random password nobody knows (visitors get in through
        // DemoLoginController, never a password), and a plain, active user.
        // This only ever writes the demo account's own row.
        $demo->forceFill([
            'name' => 'Usuario Demo',
            'email' => User::DEMO_EMAIL,
            'password' => Str::random(40),
            'role' => UserRole::User,
            'is_active' => true,
            'email_verified_at' => $demo->email_verified_at ?? now(),
        ])->save();

        $this->assertIsDemo($demo);

        DB::transaction(function () use ($demo): void {
            $this->purge($demo);

            $this->seedUnguarded($demo);
        });

        return $demo;
    }

    /**
     * Model events stay ON here (unlike `db:seed`, whose trait mutes them):
     * NormalizesToUppercase runs on `saving`, so the demo's names are stored
     * uppercased exactly like real data. Guards are off because the seeder
     * relies on forceCreate-style mass assignment, same as `db:seed`.
     */
    private function seedUnguarded(User $demo): void
    {
        Model::unguarded(fn () => app(DatabaseSeeder::class)->seedDemoDataFor($demo));
    }

    private function purge(User $demo): void
    {
        $this->assertIsDemo($demo);

        // Bulk deletes on purpose (no model events), each one restricted to
        // the demo user's own rows. Order: tournaments first, then the
        // global catalog rows (categories, clubs, referees, venues) they referenced.
        Tournament::query()->where('user_id', $demo->id)->delete();
        Category::query()->where('user_id', $demo->id)->delete();
        Club::query()->where('user_id', $demo->id)->delete();
        Referee::query()->where('user_id', $demo->id)->delete();
        Venue::query()->where('user_id', $demo->id)->delete();
    }

    private function assertIsDemo(User $user): void
    {
        if (! $user->exists || $user->email !== User::DEMO_EMAIL) {
            throw new InvalidArgumentException('Demo reset refused: not the demo account.');
        }
    }
}
