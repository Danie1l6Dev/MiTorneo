<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A plantel's group stops being a fact about the plantel and becomes one
     * about the plantel IN A TOURNAMENT: tournament_team gets its own group_id,
     * and the groups themselves belong to one tournament each (so every
     * tournament that includes a category has its own "Grupo A"/"Grupo B").
     *
     * Expand-only, so it can be rolled back without losing anything:
     *  - teams.group_id is NOT touched (nothing reads it anymore, but it keeps
     *    the old assignment until a later migration drops it);
     *  - groups.tournament_id gets filled in for the groups that had none (the
     *    catalog ones), from the one tournament their category is enrolled in;
     *  - tournament_team.group_id is copied from teams.group_id, only where it
     *    is still empty, so running it twice changes nothing.
     *
     * A group of a category enrolled in more than one tournament goes to the
     * tournament that actually used it (its matches, else its planteles). If
     * that is still not a single tournament, it is left alone and reported in
     * the log.
     */
    public function up(): void
    {
        Schema::table('tournament_team', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('team_id')->constrained('groups')->nullOnDelete();
        });

        // The (category, name) uniqueness was per category; it has to be per
        // tournament now. category_id keeps a plain index of its own first,
        // because its foreign key was leaning on the composite one.
        Schema::table('groups', function (Blueprint $table) {
            if (! Schema::hasIndex('groups', ['category_id'])) {
                $table->index('category_id');
            }
        });

        Schema::table('groups', function (Blueprint $table) {
            if (Schema::hasIndex('groups', ['category_id', 'name'], 'unique')) {
                $table->dropUnique(['category_id', 'name']);
            }

            $table->unique(['tournament_id', 'category_id', 'name']);
        });

        $this->attributeGroupsToTheirTournament();
        $this->copyTeamGroupsToTheirTournamentRows();
    }

    private function attributeGroupsToTheirTournament(): void
    {
        $tournamentsByCategory = DB::table('tournament_category')
            ->select('category_id', 'tournament_id')
            ->get()
            ->groupBy('category_id');

        foreach (DB::table('groups')->whereNull('tournament_id')->get() as $group) {
            $enrolled = $tournamentsByCategory->get($group->category_id, collect())->pluck('tournament_id')->unique();

            // The usual case: the category is enrolled in one tournament only.
            // Otherwise, the tournament that actually used the group -- its
            // matches, or failing that the tournaments where the planteles
            // that were in it are enrolled.
            $tournamentIds = $enrolled->count() === 1 ? $enrolled : $this->tournamentsThatUsedTheGroup($group->id);

            if ($tournamentIds->count() === 1) {
                DB::table('groups')->where('id', $group->id)->update(['tournament_id' => $tournamentIds->first()]);

                continue;
            }

            Log::warning('Grupo sin torneo que atribuir: no se puede saber con certeza a qué torneo pertenece.', [
                'group_id' => $group->id,
                'category_id' => $group->category_id,
                'enrolled_tournaments' => $enrolled->all(),
                'used_by_tournaments' => $tournamentIds->all(),
            ]);
        }
    }

    /**
     * @return Collection<int, int>
     */
    private function tournamentsThatUsedTheGroup(int $groupId): Collection
    {
        $fromMatches = DB::table('matches')->where('group_id', $groupId)->pluck('tournament_id')->unique();

        if ($fromMatches->isNotEmpty()) {
            return $fromMatches->values();
        }

        return DB::table('tournament_team')
            ->join('teams', 'teams.id', '=', 'tournament_team.team_id')
            ->where('teams.group_id', $groupId)
            ->pluck('tournament_team.tournament_id')
            ->unique()
            ->values();
    }

    private function copyTeamGroupsToTheirTournamentRows(): void
    {
        $rows = DB::table('tournament_team')
            ->join('teams', 'teams.id', '=', 'tournament_team.team_id')
            ->join('groups', 'groups.id', '=', 'teams.group_id')
            ->whereNull('tournament_team.group_id')
            ->whereColumn('groups.tournament_id', 'tournament_team.tournament_id')
            ->select('tournament_team.id as row_id', 'teams.group_id')
            ->get();

        foreach ($rows as $row) {
            DB::table('tournament_team')->where('id', $row->row_id)->update(['group_id' => $row->group_id]);
        }
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropUnique(['tournament_id', 'category_id', 'name']);
            $table->unique(['category_id', 'name']);
        });

        Schema::table('tournament_team', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
        });
    }
};
