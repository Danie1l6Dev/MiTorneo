<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            // Generated once when the tournament is created (see
            // Tournament::generateUniqueSlug()) and never changed afterward,
            // even if the name is edited later -- a shared public portal
            // link must never break. Nullable at the schema level only so
            // this migration doesn't have to fail on a fresh install with no
            // rows to backfill; the application always sets it going forward.
            $table->string('slug')->nullable()->unique()->after('name');
        });

        DB::table('tournaments')->select('id', 'name')->orderBy('id')->get()->each(function (object $row): void {
            $base = Str::slug($row->name) ?: 'torneo';
            $slug = $base;
            $suffix = 2;

            while (DB::table('tournaments')->where('slug', $slug)->where('id', '!=', $row->id)->exists()) {
                $slug = "{$base}-{$suffix}";
                $suffix++;
            }

            DB::table('tournaments')->where('id', $row->id)->update(['slug' => $slug]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
