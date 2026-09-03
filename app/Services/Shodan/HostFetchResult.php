<?php

namespace App\Services\Shodan;

use App\Models\HostSnapshot;

/**
 * The outcome of a host lookup: the snapshot, plus whether it was just scraped
 * or reused from the cooldown window.
 *
 * The flag exists so the view can tell the user which of the two happened. A
 * page that silently reuses cached data looks identical to one that just hit
 * the network, and the whole point of step 9 is that the difference is visible.
 */
readonly class HostFetchResult
{
    public function __construct(
        public HostSnapshot $snapshot,
        public bool $reusedExisting,
    ) {}
}
