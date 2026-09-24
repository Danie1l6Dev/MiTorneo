<?php

namespace Tests\Feature\Tournaments;

use App\Enums\MatchEventType;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The statistics leaderboards page 10 rows at a time, client-side (Alpine),
 * so switching pages never reloads.
 */
class StatisticsLeaderboardPaginationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{rank: int, player: Player, count: int}>
     */
    private function rows(int $count): array
    {
        $team = Team::factory()->create();

        return collect(range(1, $count))
            ->map(fn (int $rank): array => [
                'rank' => $rank,
                'player' => Player::factory()->for($team)->create(['full_name' => "Jugador {$rank}"])->load('team'),
                'count' => 30 - $rank,
            ])
            ->all();
    }

    private function render(array $rows): string
    {
        return Blade::render('<x-ui.statistics-leaderboard :rows="$rows" :type="$type" />', [
            'rows' => $rows,
            'type' => MatchEventType::Goal,
        ]);
    }

    public function test_more_than_ten_rows_are_split_into_client_side_pages(): void
    {
        $html = $this->render($this->rows(23));

        $this->assertStringContainsString('x-data="{ page: 1, pages: 3 }"', $html);
        $this->assertStringContainsString('Página 1 de 3', $html);
        // Row 11 opens page 2, row 21 opens page 3 -- every row is already in
        // the HTML, only shown/hidden by Alpine.
        $this->assertSame(10, substr_count($html, 'x-show="page === 1"'));
        $this->assertSame(10, substr_count($html, 'x-show="page === 2"'));
        $this->assertSame(3, substr_count($html, 'x-show="page === 3"'));
        $this->assertStringContainsString('JUGADOR 23', $html);
    }

    public function test_ten_rows_or_fewer_have_no_pager(): void
    {
        $html = $this->render($this->rows(10));

        $this->assertStringNotContainsString('Página', $html);
        $this->assertStringNotContainsString('x-show="page ===', $html);
    }
}
