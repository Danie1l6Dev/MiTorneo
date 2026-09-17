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
        // Singleton table -- always exactly one row (id 1), read/written
        // through Setting::current(). Starts with every flag off so
        // enabling a new one never changes production behaviour by
        // surprise; an admin has to opt in from admin/settings.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('sanction_pdf_uploads_enabled')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
