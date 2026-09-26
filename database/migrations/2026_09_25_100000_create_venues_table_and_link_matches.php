<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canchas as a real catalog (global to the organizer, like referees)
     * instead of the free-text matches.venue column: scheduling needs to
     * know two matches are on the SAME cancha, and free text ("Cancha
     * Boscán" vs "cancha boscan ") can't promise that. Existing free-text
     * values are folded into venues (one per organizer + name) and linked
     * before the old column is dropped.
     */
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->foreignId('venue_id')->nullable()->after('scheduled_at')->constrained('venues')->nullOnDelete();
        });

        $rows = DB::table('matches')
            ->join('tournaments', 'tournaments.id', '=', 'matches.tournament_id')
            ->whereNotNull('matches.venue')
            ->where('matches.venue', '!=', '')
            ->get(['matches.id as match_id', 'matches.venue', 'tournaments.user_id']);

        $venueIds = [];

        foreach ($rows as $row) {
            $name = mb_strtoupper(trim((string) preg_replace('/\s+/', ' ', $row->venue)));

            if ($name === '') {
                continue;
            }

            $key = $row->user_id.'|'.$name;

            $venueIds[$key] ??= DB::table('venues')
                ->where('user_id', $row->user_id)
                ->where('name', $name)
                ->value('id')
                ?? DB::table('venues')->insertGetId([
                    'user_id' => $row->user_id,
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('matches')->where('id', $row->match_id)->update(['venue_id' => $venueIds[$key]]);
        }

        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('venue');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->string('venue', 120)->nullable()->after('scheduled_at');
        });

        DB::table('matches')
            ->join('venues', 'venues.id', '=', 'matches.venue_id')
            ->get(['matches.id as match_id', 'venues.name'])
            ->each(fn ($row) => DB::table('matches')->where('id', $row->match_id)->update(['venue' => $row->name]));

        Schema::table('matches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('venue_id');
        });

        Schema::dropIfExists('venues');
    }
};
