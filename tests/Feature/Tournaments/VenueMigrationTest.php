<?php

namespace Tests\Feature\Tournaments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Tournaments\Concerns\MakesSchedulableMatches;
use Tests\TestCase;

/**
 * The migration that turns the old free-text matches.venue column into the
 * venues catalog runs against real data, so it's exercised the way production
 * will hit it: roll it back, put text venues on matches, migrate forward.
 */
class VenueMigrationTest extends TestCase
{
    use MakesSchedulableMatches, RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_25_100000_create_venues_table_and_link_matches.php';

    protected function setUp(): void
    {
        parent::setUp();

        // These tests roll a migration back, so they must never run against a
        // real database -- phpunit.xml pins the suite to in-memory SQLite.
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Only runs on the in-memory SQLite test database.');
        }
    }

    private function rollBackVenueMigration(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--realpath' => false, '--force' => true]);
    }

    private function migrateVenueMigrationForward(): void
    {
        Artisan::call('migrate', ['--path' => self::MIGRATION, '--realpath' => false, '--force' => true]);
    }

    public function test_free_text_venues_become_one_venue_per_organizer_and_name(): void
    {
        ['user' => $anna, 'tournament' => $annaTournament] = $this->makeSchedulingTournament();
        ['user' => $ben, 'tournament' => $benTournament] = $this->makeSchedulingTournament();
        $annaCategory = $this->makeSchedulingCategory($annaTournament, 'Sub-13', 2013);
        $benCategory = $this->makeSchedulingCategory($benTournament, 'Sub-13', 2013);

        $a1 = $this->makeSchedulingMatch($annaTournament, $annaCategory, 'Tigres', 'Leones', 1);
        $a2 = $this->makeSchedulingMatch($annaTournament, $annaCategory, 'Osos', 'Lobos', 1);
        $a3 = $this->makeSchedulingMatch($annaTournament, $annaCategory, 'Pumas', 'Cóndores', 1);
        $b1 = $this->makeSchedulingMatch($benTournament, $benCategory, 'Tigres', 'Leones', 1);

        $this->rollBackVenueMigration();

        $this->assertTrue(Schema::hasColumn('matches', 'venue'));
        $this->assertFalse(Schema::hasTable('venues'));

        // The same cancha typed three ways by Anna, once by Ben, none for a3.
        DB::table('matches')->where('id', $a1->id)->update(['venue' => 'Cancha Boscán']);
        DB::table('matches')->where('id', $a2->id)->update(['venue' => '  cancha   BOSCÁN ']);
        DB::table('matches')->where('id', $b1->id)->update(['venue' => 'Cancha Boscán']);

        $this->migrateVenueMigrationForward();

        $this->assertFalse(Schema::hasColumn('matches', 'venue'));
        $this->assertSame(2, DB::table('venues')->count());
        $this->assertSame(1, DB::table('venues')->where('user_id', $anna->id)->count());
        $this->assertSame(1, DB::table('venues')->where('user_id', $ben->id)->count());
        $this->assertSame('CANCHA BOSCÁN', DB::table('venues')->where('user_id', $anna->id)->value('name'));

        $annaVenueId = DB::table('venues')->where('user_id', $anna->id)->value('id');
        $this->assertSame($annaVenueId, DB::table('matches')->where('id', $a1->id)->value('venue_id'));
        $this->assertSame($annaVenueId, DB::table('matches')->where('id', $a2->id)->value('venue_id'));
        $this->assertNull(DB::table('matches')->where('id', $a3->id)->value('venue_id'));
        $this->assertSame(DB::table('venues')->where('user_id', $ben->id)->value('id'), DB::table('matches')->where('id', $b1->id)->value('venue_id'));
    }

    public function test_rolling_back_restores_the_venue_names_as_text(): void
    {
        ['user' => $user, 'tournament' => $tournament] = $this->makeSchedulingTournament();
        $venue = $this->makeSchedulingVenue($user, 'Cancha Los Ídolos');
        $category = $this->makeSchedulingCategory($tournament, 'Sub-13', 2013);
        $match = $this->makeSchedulingMatch($tournament, $category, 'Tigres', 'Leones', 1, '2026-09-12 07:30:00', $venue);

        $this->rollBackVenueMigration();

        $this->assertSame('CANCHA LOS ÍDOLOS', DB::table('matches')->where('id', $match->id)->value('venue'));
        $this->assertFalse(Schema::hasColumn('matches', 'venue_id'));

        $this->migrateVenueMigrationForward();

        $this->assertSame(1, DB::table('venues')->count());
        $this->assertNotNull(DB::table('matches')->where('id', $match->id)->value('venue_id'));
    }
}
