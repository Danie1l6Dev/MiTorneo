<?php

namespace Tests\Unit\Models;

use App\Enums\SanctionType;
use App\Models\Sanction;
use Tests\TestCase;

class SanctionClassifyCardTallyTest extends TestCase
{
    public function test_no_cards_produce_no_sanction(): void
    {
        $this->assertNull(Sanction::classifyCardTally(0, 0));
    }

    public function test_a_single_yellow_produces_no_sanction(): void
    {
        $this->assertNull(Sanction::classifyCardTally(1, 0));
    }

    public function test_two_yellows_classify_as_double_yellow(): void
    {
        $this->assertSame(SanctionType::DoubleYellow, Sanction::classifyCardTally(2, 0));
    }

    public function test_a_lone_red_with_no_yellows_classifies_as_red_card(): void
    {
        $this->assertSame(SanctionType::RedCard, Sanction::classifyCardTally(0, 1));
    }

    public function test_one_yellow_plus_a_red_classifies_as_red_card_not_double_yellow(): void
    {
        // A single caution coexisting with an unrelated straight red is a
        // normal, separate combo -- it never had 2 yellows, so the red is
        // read as genuinely direct, same interpretation
        // MatchEventController::cascadeCardDeletion() already committed to.
        $this->assertSame(SanctionType::RedCard, Sanction::classifyCardTally(1, 1));
    }

    public function test_two_yellows_plus_the_auto_red_still_classify_as_double_yellow(): void
    {
        // The "segunda amarilla" pairing (2 yellow + 1 red) is read as one
        // linked expulsion, not two separate offenses.
        $this->assertSame(SanctionType::DoubleYellow, Sanction::classifyCardTally(2, 1));
    }
}
