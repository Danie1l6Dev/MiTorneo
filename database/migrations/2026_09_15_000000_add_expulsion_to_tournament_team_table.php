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
        // A team's expulsion is scoped to its own tournament_team row --
        // i.e. to ONE team's participation in ONE tournament -- so it never
        // leaks into another category the same club fields, nor into a
        // later tournament the same global team re-enters. See
        // TeamExpulsionService.
        Schema::table('tournament_team', function (Blueprint $table) {
            $table->timestamp('expelled_at')->nullable()->after('team_id');
            $table->text('expulsion_reason')->nullable()->after('expelled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tournament_team', function (Blueprint $table) {
            $table->dropColumn(['expelled_at', 'expulsion_reason']);
        });
    }
};
