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
        // Some mixed categories (mostly the youngest ones) let girls play
        // with boys but allow them to be a few years older than the boys'
        // own cutoff -- see Player::ageEligibleForCategory(). Null/0 means
        // no extra allowance, i.e. today's behavior is unchanged.
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedTinyInteger('female_extra_birth_years')->nullable()->after('birth_year_to');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('female_extra_birth_years');
        });
    }
};
