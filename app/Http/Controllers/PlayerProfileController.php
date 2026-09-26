<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Services\PlayerProfileService;
use Illuminate\View\View;

/**
 * One player's ficha: personal data, current club and planteles, statistics per
 * tournament, cards, sanctions and the (deduced) clubs and planteles timeline.
 * Read-only; editing the player stays on players.edit.
 */
class PlayerProfileController extends Controller
{
    public function show(Player $player, PlayerProfileService $profiles): View
    {
        $this->authorize('view', $player);

        return view('pages.players.show', $profiles->profile($player));
    }
}
