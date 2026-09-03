<?php

namespace App\Jobs;

use App\Models\Scan;
use App\Services\Shodan\ScanRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a scan outside the request/response cycle.
 *
 * Not a refinement: the HTTP client enforces 10 seconds between requests, and a
 * scan can spend up to 150 of them. Running it synchronously would leave the
 * browser hanging for twenty minutes. The scan view refreshes itself for as
 * long as the status says "running".
 */
class RunScan implements ShouldQueue
{
    use Queueable;

    /** A single attempt: we never replay a half finished scan against Shodan. */
    public int $tries = 1;

    /** Generous: 150 requests spaced 10 s apart already take 25 minutes. */
    public int $timeout = 1800;

    public function __construct(public Scan $scan) {}

    public function handle(ScanRunner $runner): void
    {
        $runner->run($this->scan);
    }

    /** A failed scan keeps what it harvested, and records why it stopped. */
    public function failed(?Throwable $exception): void
    {
        Log::error('geoscan.scan_failed', [
            'scan_id' => $this->scan->id,
            'message' => $exception?->getMessage(),
        ]);

        $this->scan->update([
            'status' => Scan::STATUS_FAILED,
            'failure_reason' => $exception?->getMessage(),
            'finished_at' => now(),
            'unique_hosts' => $this->scan->results()->count(),
        ]);
    }
}
