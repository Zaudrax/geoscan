<?php

namespace App\Http\Controllers;

use App\Models\Watch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Watches: searches replayed on a fixed interval.
 *
 * No method here touches the network. Creating a watch triggers nothing; the
 * scheduler queues a scan at the next due time. That is deliberate -- a watch
 * is an intention, not an action.
 */
class WatchController extends Controller
{
    /** Every watch, active ones first, each with enough scans to diff. */
    public function index(): View
    {
        return view('watches.index', [
            'watches' => Watch::with(['scans' => fn ($query) => $query->limit(2)])
                ->orderByDesc('is_active')
                ->orderBy('label')
                ->get(),
        ]);
    }

    /** The creation form. */
    public function create(): View
    {
        return view('watches.create', ['countries' => config('countries')]);
    }

    /** Records the intention. The first pass happens on the scheduler's clock. */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'country_code' => ['required', 'string', 'size:2', 'in:'.implode(',', array_keys(config('countries')))],
            'base_term' => ['required', 'string', 'min:2', 'max:255'],
            // Floored at one hour: below that the crawl delay would make two
            // runs of the same watch overlap.
            'interval_hours' => ['required', 'integer', 'between:1,720'],
        ], attributes: [
            'label' => 'intitulé',
            'country_code' => 'pays',
            'base_term' => 'terme',
            'interval_hours' => 'intervalle',
        ]);

        $watch = Watch::create($validated);

        return redirect()->route('watches.show', $watch)->with(
            'status',
            'Veille enregistrée. Le premier scan partira au prochain passage du planificateur.'
        );
    }

    /** One watch: what appeared since the previous pass, and its history. */
    public function show(Watch $watch): View
    {
        $watch->load('scans');

        return view('watches.show', [
            'watch' => $watch,
            'newcomers' => $watch->newcomers(),
        ]);
    }

    /**
     * Suspends or resumes a watch.
     *
     * Offered instead of deletion: pausing keeps the scans already collected,
     * and those are the baseline every future comparison needs.
     */
    public function toggle(Watch $watch): RedirectResponse
    {
        $watch->update(['is_active' => ! $watch->is_active]);

        return back()->with('status', $watch->is_active ? 'Veille reprise.' : 'Veille suspendue.');
    }
}
