<?php

namespace App\Http\Controllers;

use App\Exceptions\ScrapingException;
use App\Models\Host;
use App\Models\ScanResult;
use App\Services\Shodan\HostScraper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Host records and their timeline.
 *
 * Note that `show` is a GET that writes: every visit attempts a fresh fetch,
 * subject to the cooldown. That is why it sits behind authentication with the
 * write routes rather than with the read-only archive -- an HTTP verb says
 * nothing about a handler's real effects.
 */
class HostController extends Controller
{
    /** The "look up an IP" form, with the most recently seen hosts. */
    public function create(): View
    {
        return view('hosts.create', [
            'hosts' => Host::with('latestSnapshot')->latest('updated_at')->take(10)->get(),
        ]);
    }

    /**
     * Validates the IP, then hands over to `show`.
     *
     * A redirect rather than rendering here, so the resulting page has a
     * shareable URL and a reload does not re-post the form.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ip' => ['required', 'ip'],
        ], attributes: [
            'ip' => 'adresse IP',
        ]);

        return redirect()->route('hosts.show', $validated['ip']);
    }

    /**
     * A host record: current state, exposure reading, known CVEs, timeline.
     *
     * A scraping failure does not take the page down. We keep whatever is in
     * the database and say what went wrong, because a stale record is still
     * worth reading.
     */
    public function show(string $ip, HostScraper $scraper): View
    {
        abort_unless(filter_var($ip, FILTER_VALIDATE_IP), 404);

        $error = null;
        $result = null;

        try {
            $result = $scraper->fetch($ip);
        } catch (ScrapingException $e) {
            $error = $e->getMessage();
        }

        $host = Host::with('snapshots')->where('ip', $ip)->firstOrFail();

        return view('hosts.show', [
            'host' => $host,
            'snapshots' => $host->snapshots,       // already newest first
            'current' => $result?->snapshot ?? $host->snapshots->first(),
            'reused' => $result?->reusedExisting ?? false,
            'error' => $error,
            'tags' => $this->tagsFor($ip),
        ]);
    }

    /**
     * The tags Shodan attached to this IP.
     *
     * They only ever appear on search pages, never on a host record, so we look
     * them up in our own scan results instead. Without them a honeypot would be
     * presented as a critical risk while its data is most likely fabricated.
     *
     * @return list<string>
     */
    private function tagsFor(string $ip): array
    {
        return ScanResult::query()
            ->where('ip', $ip)
            ->whereNotNull('tags')
            ->pluck('tags')
            ->flatten()
            ->unique()
            ->values()
            ->all();
    }
}
