<?php

namespace App\Http\Controllers;

use App\Exceptions\ScrapingException;
use App\Models\Search;
use App\Services\Shodan\SearchScraper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Searches: one scraped result page, archived as it looked at that moment.
 *
 * The split between the two halves of this controller is the point of step 8 of
 * the assignment: create/store reach out to shodan.io, index/show only ever
 * read our own database. Consulting an archive and re-running a search are two
 * different acts, and the code says so.
 */
class SearchController extends Controller
{
    /** The search form. */
    public function create(): View
    {
        return view('searches.create');
    }

    /** Triggers a real scrape, then redirects to the archive it created. */
    public function store(Request $request, SearchScraper $scraper): RedirectResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:255'],
        ], attributes: [
            'query' => 'requête',
        ]);

        try {
            $search = $scraper->scrape($validated['query']);
        } catch (ScrapingException $e) {
            // A scraping failure is not a server error: the form comes back
            // with the reason, which is usually actionable (login wall, quota).
            return back()->withInput()->withErrors(['query' => $e->getMessage()]);
        }

        return redirect()
            ->route('searches.show', $search)
            ->with('status', 'Recherche scrapée et archivée.');
    }

    /** The history: every search already archived, newest first. */
    public function index(): View
    {
        return view('searches.index', [
            'searches' => Search::latest('scraped_at')->paginate(20),
        ]);
    }

    /**
     * Reading one archive.
     *
     * 100% database: no outbound request is triggered here. Eager loading both
     * relations keeps the view free of N+1 queries while it renders the
     * rankings and the result list.
     */
    public function show(Search $search): View
    {
        $search->load('facets', 'results');

        return view('searches.show', [
            'search' => $search,
            'facetsByType' => $search->facetsByType(),
            'results' => $search->results,
        ]);
    }
}
