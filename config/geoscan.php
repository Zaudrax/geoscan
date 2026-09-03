<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scraping target
    |--------------------------------------------------------------------------
    */
    'base_url' => env('SHODAN_BASE_URL', 'https://www.shodan.io'),

    /*
    |--------------------------------------------------------------------------
    | Scraper identity
    |--------------------------------------------------------------------------
    | A polite scraper announces itself: the site's administrator must be able
    | to identify us and reach us from their access logs.
    */
    'user_agent' => env(
        'SHODAN_USER_AGENT',
        'GeoScanBot/1.0 (+projet pedagogique IT-Akademy; contact: contact@example.test)'
    ),

    /*
    |--------------------------------------------------------------------------
    | Minimum delay between two outbound requests (seconds)
    |--------------------------------------------------------------------------
    | shodan.io/robots.txt announces "Crawl-delay: 10". We take that value as
    | the default floor: do not lower it without a reason.
    */
    'request_delay' => (int) env('SHODAN_REQUEST_DELAY', 10),

    /*
    |--------------------------------------------------------------------------
    | Host record cooldown (seconds)
    |--------------------------------------------------------------------------
    | When a snapshot of this IP is younger than X seconds, we reuse it instead
    | of hitting Shodan again. This guard is what prevents scraping on every
    | single click (step 9 of the assignment).
    */
    'host_cooldown' => (int) env('SHODAN_HOST_COOLDOWN', 300),

    /*
    |--------------------------------------------------------------------------
    | HTTP timeout (seconds)
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('SHODAN_TIMEOUT', 20),

    /*
    |--------------------------------------------------------------------------
    | Retries on server errors
    |--------------------------------------------------------------------------
    | shodan.io's edge intermittently returns a 503 "upstream connect error"
    | that has nothing to do with the request: replaying the very same one
    | succeeds. Without a retry, a scan of dozens of sub-requests dies on the
    | first hiccup.
    |
    | Only applies to 5xx and timeouts. Keep it low: every retry is one more
    | real request, and it sits outside the scan's budget.
    */
    'retries' => (int) env('SHODAN_RETRIES', 2),

    /*
    |--------------------------------------------------------------------------
    | Paths disallowed by robots.txt
    |--------------------------------------------------------------------------
    | Observed 2026-08-31 at https://www.shodan.io/robots.txt:
    |
    |     User-agent: *
    |     Crawl-delay: 10
    |     Disallow: /domain/
    |
    | The HTTP client refuses, on its own, any URL starting with these
    | prefixes -- the request is never even built.
    */
    'disallowed_paths' => ['/domain/'],

    /*
    |--------------------------------------------------------------------------
    | Pagination of a plain search
    |--------------------------------------------------------------------------
    | Maximum number of pages SearchScraper follows to bring back a search's
    | full pool. It stops anyway as soon as a page adds no new host: on a free
    | account (capped at 2 pages) it therefore wastes exactly one request. Only
    | raise this if the configured account has a subscription, otherwise it is
    | pointless noise.
    */
    /*
    |--------------------------------------------------------------------------
    | Known vulnerabilities (CVE)
    |--------------------------------------------------------------------------
    | Shodan attaches to each host the published flaws for the versions it
    | detected. The volume is wildly uneven: a handful on an ordinary machine,
    | more than a thousand on a decoy simulating dozens of services. So we cap
    | what we STORE, keeping the highest CVSS scores, and keep the exact count
    | beside it.
    */
    'vulnerabilities' => [
        'max_stored' => (int) env('SHODAN_MAX_VULNERABILITIES_STORED', 50),

        // Lookup link. The NVD is authoritative and its URLs are stable.
        'reference_url' => env('CVE_REFERENCE_URL', 'https://nvd.nist.gov/vuln/detail/'),
    ],

    'search' => [
        'max_pages' => (int) env('SHODAN_SEARCH_MAX_PAGES', 12),
    ],

    'enumeration' => [
        /*
         * A run's request ceiling. It now only bounds the damage: since slices
         * are batched (see batchable_facets), a pool of 80 results harvests in
         * about ten requests instead of 78 one by one. Measured 2026-09-03: a
         * budget of 30 left 49 of the 77 port slices never visited.
         */
        'max_requests' => (int) env('SHODAN_MAX_REQUESTS_PER_RUN', 150),

        /*
         * Maximum depth of the descent. The residual is split on the same facet
         * round after round (see probeResidual), and each round adds a filter.
         * A query that stopped shrinking would spin until the budget ran dry;
         * this bound stops it first.
         */
        'max_depth' => (int) env('SHODAN_MAX_DEPTH', 24),

        /*
         * Maximum query length, in characters. Only the negation descent can
         * approach it: each round adds a "-port:<a hundred values>" token,
         * roughly six hundred characters.
         *
         * Measured 2026-09-03, by excluding fabricated ports:
         *
         *     1,835 characters -> 200
         *     4,835 characters -> 200
         *     9,635 characters -> 503 (rejected by the edge, not the engine)
         *
         * 4500 therefore leaves headroom below the first observed failure, i.e.
         * about seven negation rounds, i.e. some 700 distinct port values. That
         * is the enumeration's real bound, and note that it applies to the
         * number of distinct VALUES, not to the number of results.
         */
        'max_query_length' => (int) env('SHODAN_MAX_QUERY_LENGTH', 4500),

        // Pages actually readable without a subscription.
        'page_limit' => (int) env('SHODAN_PAGE_LIMIT', 2),

        // Results Shodan shows per page.
        'per_page' => 10,

        /*
         * Facets whose values can be listed inside a single filter, separated
         * by commas -- "port:8081,8888,19222" behaves as an OR. The scan uses
         * this for two things: batching tiny slices into one request, and
         * describing in the negative what the ranking never named.
         *
         * Verified 2026-09-03 for port only. Only add a facet here once you
         * have tested it: a textual value such as org:"Multinet24 Sp.zoo"
         * contains spaces and quotes, and nothing guarantees Shodan reads it
         * inside a list.
         */
        'batchable_facets' => ['port'],

        // Splitting dimensions, tried in this order. Ports first: it is the
        // most discriminating facet and the least ambiguous.
        'split_facets' => ['port', 'product', 'org', 'city', 'os'],

        /*
         * Follow the "More..." link (/search/facet) when the 5 displayed values
         * sum to less than the total, a sign that a distribution tail is
         * escaping the split. Costs one request, but it is often the only way
         * to reach the last results.
         */
        'expand_facets' => (bool) env('SHODAN_EXPAND_FACETS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Geocoding (Photon / OpenStreetMap)
    |--------------------------------------------------------------------------
    | Shodan exposes no coordinates, neither on /search nor on /host. To place
    | IPs on a map we resolve "city, country" exactly once, then keep the answer
    | in the database forever (geo_points table).
    |
    | Why Photon rather than Nominatim: Nominatim's public instance answers 403
    | to our identifiable User-Agent (observed 2026-09-01), and the only way
    | through would be to pose as a browser -- the exact opposite of this
    | project's crawl policy. Photon is a geocoder built on the same
    | OpenStreetMap data, and it accepts a client that announces itself.
    |
    | Response parsing accepts both formats, Photon (GeoJSON) and Nominatim
    | (flat list), so pointing `endpoint` at a self-hosted Nominatim instance
    | works without touching any code.
    */
    'geocoding' => [
        'enabled' => (bool) env('GEOCODING_ENABLED', true),
        'endpoint' => env('GEOCODING_ENDPOINT', 'https://photon.komoot.io/api/'),
        'user_agent' => env(
            'GEOCODING_USER_AGENT',
            'GeoScanBot/1.0 (+projet pedagogique IT-Akademy; contact: contact@example.test)'
        ),
        'request_delay' => (int) env('GEOCODING_REQUEST_DELAY', 1),
        'timeout' => (int) env('GEOCODING_TIMEOUT', 15),

        /*
         * Extra parameters attached to every call. Empty for Photon, which
         * answers 400 as soon as it receives an unknown parameter -- including
         * the format=jsonv2 that Nominatim expects. To target a Nominatim
         * instance: ['format' => 'jsonv2'].
         */
        'extra_params' => [],

        /*
         * Maximum number of places resolved during a single map render. At one
         * request per second, an uncapped brand new map would leave the user
         * waiting. The rest resolves on subsequent renders.
         */
        'max_lookups' => (int) env('GEOCODING_MAX_LOOKUPS', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Extension (outside the assignment's scope): authenticated session
    |--------------------------------------------------------------------------
    | The assignment itself is done as an anonymous visitor. But Shodan refuses
    | EVERY search filter (country:, port:, org:...) to a logged out visitor: it
    | answers 200 with a "Please log in to use search filters" banner. The
    | enumerator rests entirely on those filters, so it requires a session.
    |
    | Two ways to supply that session, your choice, in .env (never hardcoded
    | here, never committed: .env is gitignored).
    |
    | 1. SHODAN_SESSION_COOKIE -- the Cookie header copied from an already
    |    logged in browser. This is the only possible route for an account
    |    created through "Sign in with Google": such accounts have no password,
    |    so there is no form to replay. It is also the most frugal: zero
    |    authentication requests.
    |
    | 2. SHODAN_EMAIL + SHODAN_PASSWORD -- replays the login form. Only works
    |    for an account that genuinely has a password.
    |
    | The cookie wins when both are set.
    */
    'login' => [
        'enabled' => (bool) env('SHODAN_LOGIN_ENABLED', false),

        /*
         * The complete Cookie header, as copied from developer tools:
         * "polito=...; session=...". We target no cookie name in particular --
         * Shodan may add or rename them, and copying the whole header removes
         * any need to guess.
         */
        'session_cookie' => env('SHODAN_SESSION_COOKIE'),

        'email' => env('SHODAN_EMAIL'),
        'password' => env('SHODAN_PASSWORD'),
        'url' => env('SHODAN_LOGIN_URL', 'https://account.shodan.io/login'),

        // Lifetime of the cached cookie jar. A Shodan session lasts far
        // longer, but we would rather reconnect regularly than drag a dead
        // cookie around.
        'session_ttl' => (int) env('SHODAN_SESSION_TTL', 3600),
    ],
];
