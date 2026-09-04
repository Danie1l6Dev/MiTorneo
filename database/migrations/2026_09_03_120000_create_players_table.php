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
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('document_number');
            $table->unsignedSmallInteger('jersey_number');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // "Único entre activos" es una regla de negocio validada en
            // PlayerRequest (no una constraint de BD): un jugador inactivo
            // puede conservar históricamente el mismo dorsal o documento que
            // uno activo. Estos índices solo aceleran esas consultas.
            $table->index(['team_id', 'is_active']);
            $table->index(['team_id', 'jersey_number']);
            $table->index(['team_id', 'document_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
