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
        // Plan: docs/plan-reestructuracion/01-clubes-equipos-categorias-globales.md
        // T01-06 -- which global categories a tournament includes. Replaces
        // what categories.tournament_id does today; populated by the
        // backfill command (T01-15/T01-16) from the existing relation before
        // that column is ever touched.
        Schema::create('tournament_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tournament_id', 'category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tournament_category');
    }
};
