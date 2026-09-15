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
        // T01-01/T01-02 -- "Expandir" step (T01-14): categories become a
        // per-organizer global catalog instead of belonging to a single
        // tournament. user_id and the birth-year range are added nullable on
        // purpose -- existing rows have neither until the backfill command
        // (T01-15/T01-16) populates them. tournament_id is deliberately kept
        // for now (dropped only in the later "Contraer" migration, T01-21) so
        // the app keeps working unchanged until the cutover (T01-20).
        // Guarded with hasColumn checks: a previous deploy partially applied
        // this migration (added user_id) before failing and rolling back the
        // migrations-table record, so a retry must skip columns that already
        // exist instead of erroring on them.
        $userIdExisted = Schema::hasColumn('categories', 'user_id');

        Schema::table('categories', function (Blueprint $table) use ($userIdExisted) {
            if (! $userIdExisted) {
                $table->foreignId('user_id')->nullable()->after('tournament_id')->constrained()->cascadeOnDelete();
            }
            if (! Schema::hasColumn('categories', 'birth_year_from')) {
                $table->unsignedSmallInteger('birth_year_from')->nullable()->after('description');
            }
            if (! Schema::hasColumn('categories', 'birth_year_to')) {
                $table->unsignedSmallInteger('birth_year_to')->nullable()->after('birth_year_from');
            }
        });

        // The column may already exist from a prior partially-applied deploy
        // without its foreign key (e.g. added, then the run failed before
        // the constraint was recorded as complete) -- add it separately.
        if ($userIdExisted) {
            $hasForeignKey = collect(Schema::getForeignKeys('categories'))
                ->contains(fn (array $fk) => $fk['columns'] === ['user_id']);

            if (! $hasForeignKey) {
                Schema::table('categories', function (Blueprint $table) {
                    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (Schema::hasColumn('categories', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
            $columns = array_filter(['birth_year_from', 'birth_year_to'], fn ($column) => Schema::hasColumn('categories', $column));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
