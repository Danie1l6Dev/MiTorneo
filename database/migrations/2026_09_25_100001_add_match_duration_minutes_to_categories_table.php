<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How long a match of this category takes on the field (the official
     * programming sheets space Baby every 20 min, Cebollita every 30 and the
     * rest every 60). Null means "use the default" -- see
     * Category::matchDurationMinutes().
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedSmallInteger('match_duration_minutes')->nullable()->after('female_extra_birth_years');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('match_duration_minutes');
        });
    }
};
