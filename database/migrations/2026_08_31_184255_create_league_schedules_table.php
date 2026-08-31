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
        Schema::create('league_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competition_phase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('format');
            $table->dateTime('generated_at');
            $table->timestamps();

            $table->unique(['competition_phase_id', 'group_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('league_schedules');
    }
};
