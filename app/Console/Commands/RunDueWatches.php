<?php

namespace App\Console\Commands;

use App\Jobs\RunScan;
use App\Models\Scan;
use App\Models\Watch;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Replays every watch that has reached its interval.
 *
 * This command scrapes nothing itself: it creates one scan per due watch and
 * hands it to the queue. A scan takes minutes because of the crawl delay, and
 * doing that work inside the scheduler would block every other task and make
 * the cron run's duration unpredictable.
 */
class RunDueWatches extends Command
{
    protected $signature = 'geoscan:watches
                            {--force : replay every active watch, due or not}';

    protected $description = 'Queue a scan for each watch that has reached its interval';

    public function handle(): int
    {
        $watches = $this->dueWatches();

        if ($watches->isEmpty()) {
            $this->info('Aucune veille due.');

            return self::SUCCESS;
        }

        $watches->each($this->launch(...));

        $this->info($watches->count().' veille(s) lancée(s).');

        return self::SUCCESS;
    }

    /**
     * Active watches whose interval has elapsed.
     *
     * Filtered in memory rather than in SQL: the interval lives in a column,
     * so expressing the comparison as a query would need a raw, driver
     * specific date expression. There are never many watches, so readable and
     * portable wins. See Watch::isDue().
     *
     * @return Collection<int, Watch>
     */
    private function dueWatches(): Collection
    {
        $active = Watch::active()->get();

        return $this->option('force') ? $active : $active->filter->isDue()->values();
    }

    /** Records the pass, then queues the work it stands for. */
    private function launch(Watch $watch): void
    {
        $scan = Scan::create([
            'watch_id' => $watch->id,
            'country_code' => $watch->country_code,
            'base_term' => $watch->base_term,
            'base_query' => $watch->shodanQuery(),
            'max_requests' => (int) config('geoscan.enumeration.max_requests', 150),
            'status' => Scan::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        // Stamped BEFORE the work runs: if the scan fails, the watch must not
        // start over on every single tick of the scheduler.
        $watch->update(['last_run_at' => now()]);

        RunScan::dispatch($scan);

        $this->line("Veille « {$watch->label} » : scan #{$scan->id} mis en file.");
    }
}
