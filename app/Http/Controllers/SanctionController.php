<?php

namespace App\Http\Controllers;

use App\Enums\SanctionStatus;
use App\Http\Requests\ResolutionPdfRequest;
use App\Http\Requests\SanctionResolveRequest;
use App\Models\Sanction;
use App\Models\Setting;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SanctionController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Sanction::class);

        $sanctions = Sanction::query()
            ->whereHas('match.tournament', fn ($query) => $query->where('user_id', Auth::id()))
            ->with(['player', 'coach', 'team', 'match.tournament', 'match.category'])
            ->latest('id')
            ->get();

        // Split into the three states the index page actually shows as
        // separate sections -- mutually exclusive and exhaustive for every
        // sanction that isn't in the "shouldn't happen" edge case Sanction's
        // own docblock calls out (resolved with matches_banned still null),
        // same reasoning the stat cards above the list already relied on.
        $pendingSanctions = $sanctions->filter->isPending()->values();
        $activeSanctions = $sanctions->filter->isActive()->values();
        $fulfilledSanctions = $sanctions->filter->isFulfilled()->values();

        $expelledTeams = $this->expelledTeams();

        // The stat cards at the top of the page cover EVERY sanction --
        // player/DT and team expulsion alike -- even though the two are
        // still listed in separate sections below (see the view). An
        // expulsion is read through Team::isExpulsionPendingFor()/
        // isExpulsionActiveFor()/isExpulsionFulfilledFor(): no resolution
        // attached yet is pending; resolved but the category still has
        // unfinished matches is active; resolved AND the category's whole
        // competition (every phase, including any added later) is finished
        // is fulfilled.
        $expelledPendingCount = collect($expelledTeams)->filter(fn (array $e): bool => $e['team']->isExpulsionPendingFor($e['tournament']))->count();
        $expelledActiveCount = collect($expelledTeams)->filter(fn (array $e): bool => $e['team']->isExpulsionActiveFor($e['tournament']))->count();
        $expelledFulfilledCount = collect($expelledTeams)->filter(fn (array $e): bool => $e['team']->isExpulsionFulfilledFor($e['tournament']))->count();

        $totalPendingCount = $pendingSanctions->count() + $expelledPendingCount;
        $totalActiveCount = $activeSanctions->count() + $expelledActiveCount;
        $totalFulfilledCount = $fulfilledSanctions->count() + $expelledFulfilledCount;

        return view('pages.sanctions.index', compact(
            'sanctions', 'pendingSanctions', 'activeSanctions', 'fulfilledSanctions', 'expelledTeams',
            'totalPendingCount', 'totalActiveCount', 'totalFulfilledCount'
        ));
    }

    /**
     * Every plantel currently expelled from one of this organizer's
     * tournaments (see TeamExpulsionService) -- a fundamentally different
     * kind of sanction from a player/coach's (no pending/served-fechas
     * lifecycle, see Sanction's own docblock), so it's kept out of the
     * Sanction model entirely and shown as its own section on this same
     * page instead. One row per (team, tournament): a global team expelled
     * from one tournament and later re-entered into another shows up only
     * for the tournament it's actually still expelled from.
     *
     * @return array<int, array{team: Team, tournament: Tournament, expelled_at: string, reason: string|null, resolution_pdf_path: string|null}>
     */
    private function expelledTeams(): array
    {
        $expulsions = [];

        foreach (Auth::user()->tournaments()->get() as $tournament) {
            $expelled = $tournament->globalTeams()
                ->wherePivotNotNull('expelled_at')
                ->with(['category', 'club'])
                ->get();

            foreach ($expelled as $team) {
                $expulsions[] = [
                    'team' => $team,
                    'tournament' => $tournament,
                    'expelled_at' => (string) $team->pivot->getAttribute('expelled_at'),
                    'reason' => $team->pivot->getAttribute('expulsion_reason'),
                    'resolution_pdf_path' => $team->pivot->getAttribute('expulsion_resolution_pdf_path'),
                ];
            }
        }

        usort($expulsions, fn (array $a, array $b): int => $b['expelled_at'] <=> $a['expelled_at']);

        return $expulsions;
    }

    public function show(Sanction $sanction): View
    {
        $this->authorize('view', $sanction);

        $pdfUploadsEnabled = Setting::sanctionPdfUploadsEnabled();

        return view('pages.sanctions.show', compact('sanction', 'pdfUploadsEnabled'));
    }

    public function resolve(SanctionResolveRequest $request, Sanction $sanction): RedirectResponse
    {
        $this->authorize('resolve', $sanction);

        if (! $sanction->isPending()) {
            return back()->with('error', __('Esta sanción ya fue resuelta.'));
        }

        // hasFile() reports whatever the raw request carries, regardless of
        // rules() -- gating on the flag here too keeps a stray upload from
        // being stored while it's supposed to be off (a storage-limited
        // production server).
        $resolutionPdfPath = Setting::sanctionPdfUploadsEnabled() && $request->hasFile('resolution_pdf')
            ? $request->file('resolution_pdf')->store('resoluciones', 'public')
            : null;

        $sanction->update([
            'matches_banned' => $request->validated('matches_banned'),
            'resolution_notes' => $resolutionPdfPath === null ? $request->validated('resolution_notes') : null,
            'resolution_pdf_path' => $resolutionPdfPath,
            'fine_amount' => $sanction->coach_id !== null ? $request->validated('fine_amount') : null,
            'status' => SanctionStatus::Resolved,
            'resolved_at' => now(),
        ]);

        return to_route('sanctions.show', $sanction)->with('status', __('Sanción resuelta correctamente.'));
    }

    /**
     * Attaches or replaces the resolution PDF on an already-resolved
     * sanction -- e.g. the wrong file was uploaded when resolving, or the
     * feature got turned on afterward and the committee's PDF still needs
     * to be attached. Doesn't touch matches_banned/fine_amount/resolved_at:
     * only the resolution's supporting document changes.
     */
    public function updateResolutionPdf(ResolutionPdfRequest $request, Sanction $sanction): RedirectResponse
    {
        $this->authorize('resolve', $sanction);

        if (! $sanction->isResolved()) {
            return back()->with('error', __('Esta sanción todavía no fue resuelta.'));
        }

        if (! Setting::sanctionPdfUploadsEnabled()) {
            return back()->with('error', __('La carga de PDF está deshabilitada.'));
        }

        if ($sanction->resolution_pdf_path !== null) {
            Storage::disk('public')->delete($sanction->resolution_pdf_path);
        }

        $sanction->update([
            'resolution_pdf_path' => $request->file('resolution_pdf')->store('resoluciones', 'public'),
            'resolution_notes' => null,
        ]);

        return back()->with('status', __('PDF de la resolución actualizado.'));
    }

    /**
     * Removes a mistakenly attached resolution PDF -- always allowed, even
     * with the feature currently off, since it's pure cleanup and not a new
     * upload. Leaves the sanction resolved with no resolution document on
     * file, same as if none had ever been attached.
     */
    public function destroyResolutionPdf(Sanction $sanction): RedirectResponse
    {
        $this->authorize('resolve', $sanction);

        if ($sanction->resolution_pdf_path === null) {
            return back()->with('error', __('Esta sanción no tiene un PDF de resolución para quitar.'));
        }

        Storage::disk('public')->delete($sanction->resolution_pdf_path);
        $sanction->update(['resolution_pdf_path' => null]);

        return back()->with('status', __('PDF de la resolución eliminado.'));
    }
}
