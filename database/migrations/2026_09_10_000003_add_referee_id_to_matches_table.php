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
            // Optional -- a match can exist, and even be played, without a
            // referee ever being recorded. Nulling the FK on delete keeps a
            // finished match's result intact if the referee record is ever
            // removed.
            $table->foreignId('referee_id')->nullable()->after('group_id')
                ->constrained('referees')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referee_id');
        });
    }
};
