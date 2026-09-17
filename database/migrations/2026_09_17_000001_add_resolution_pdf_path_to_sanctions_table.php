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
        Schema::table('sanctions', function (Blueprint $table) {
            $table->string('resolution_pdf_path')->nullable()->after('resolution_notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sanctions', function (Blueprint $table) {
            $table->dropColumn('resolution_pdf_path');
        });
    }
};
