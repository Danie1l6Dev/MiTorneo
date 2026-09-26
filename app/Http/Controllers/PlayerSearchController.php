<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Services\PlayerProfileService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * "Buscar jugador": every player of the organizer is sent once and filtered in
 * the browser as they type (by document, name or surname, or a club they have
 * been at) -- see pages/players/index.blade.php.
 */
class PlayerSearchController extends Controller
{
    public function index(PlayerProfileService $profiles): View
    {
        $this->authorize('viewAny', Player::class);

        return view('pages.players.index', [
            'catalog' => $profiles->searchCatalog(Auth::id()),
        ]);
    }
}
