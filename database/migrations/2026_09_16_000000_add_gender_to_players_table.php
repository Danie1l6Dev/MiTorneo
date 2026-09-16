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
        // Nullable on purpose, same as birth_date: every player already in
        // production has no gender on file yet -- see the "dato incompleto"
        // warning icon on the player row and Category::female_extra_birth_years
        // for the rule this unlocks (mixed-category catalogs that let girls
        // play up more years than boys).
        Schema::table('players', function (Blueprint $table) {
            $table->string('gender')->nullable()->after('birth_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
