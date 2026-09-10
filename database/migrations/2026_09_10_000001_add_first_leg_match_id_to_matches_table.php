<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            // Set only on the second leg of a two-legged knockout cross,
            // pointing back at its first leg -- null for every league match
            // and every single-match knockout cross. This is what lets the
            // engine tell a decisive leg apart from one still awaiting its
            // other half, and pair the two legs' scores into an aggregate.
            $table->foreignId('first_leg_match_id')->nullable()->after('competition_phase_id')
                ->constrained('matches')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('first_leg_match_id');
        });
    }
};
