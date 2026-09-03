<?php

namespace App\Http\Controllers;

use App\Models\OutboundRequest;
use Illuminate\View\View;

/**
 * The compliance journal.
 *
 * The assignment asks us to hold a delay between requests and to know what
 * robots.txt allows. This page does not assert either: it shows them, request
 * by request, with the delay actually observed before each one.
 *
 * No outbound traffic: it reads back what ShodanClient recorded.
 */
class JournalController extends Controller
{
    /** The policy in force, a summary, and the raw log. */
    public function index(): View
    {
        $requests = OutboundRequest::latest('sent_at')->paginate(50);

        return view('journal.index', [
            'requests' => $requests,
            'policy' => [
                'user_agent' => (string) config('geoscan.user_agent'),
                'delay' => (int) config('geoscan.request_delay'),
                'disallowed' => (array) config('geoscan.disallowed_paths'),
                'timeout' => (int) config('geoscan.timeout'),
            ],
            'stats' => $this->statistics(),
        ]);
    }

    /**
     * The summary that makes the journal readable at a glance.
     *
     * The MINIMUM observed delay is the number that matters: it is the one you
     * hold against robots.txt's "Crawl-delay: 10". An average would hide a
     * burst sitting between two long pauses.
     *
     * @return array<string, mixed>
     */
    private function statistics(): array
    {
        $total = OutboundRequest::count();

        return [
            'total' => $total,
            'blocked' => OutboundRequest::where('outcome', OutboundRequest::OUTCOME_BLOCKED_BY_ROBOTS)->count(),
            'failed' => OutboundRequest::where('outcome', OutboundRequest::OUTCOME_FAILED)->count(),
            'first' => OutboundRequest::min('sent_at'),
            'last' => OutboundRequest::max('sent_at'),
            // Only counts requests that actually went out: a blocked one
            // never waited for anybody.
            'shortest_gap' => OutboundRequest::whereNotNull('waited_seconds')
                ->where('outcome', '!=', OutboundRequest::OUTCOME_BLOCKED_BY_ROBOTS)
                ->min('waited_seconds'),
        ];
    }
}
