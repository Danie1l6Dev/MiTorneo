<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a match is played (e.g. "Cancha Parque Boscán") -- free text and
     * optional, like the date and referee. The programming PDF groups by it.
     */
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->string('venue', 120)->nullable()->after('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('venue');
        });
    }
};
