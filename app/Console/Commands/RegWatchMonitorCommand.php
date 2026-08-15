<?php

namespace App\Console\Commands;

use App\Jobs\RegWatch\CheckRegWatchSource;
use App\Models\RegWatchSource;
use App\Services\RegWatch\SourceMonitor;
use Illuminate\Console\Command;

class RegWatchMonitorCommand extends Command
{
    protected $signature = 'regwatch:monitor
        {--source= : Limit the run to one source slug}
        {--sync : Check sources inline instead of dispatching queue jobs}';

    protected $description = 'Fetch active RegWatch sources, diff against stored snapshots and record detections for review';

    public function handle(SourceMonitor $monitor): int
    {
        if (! config('regwatch.enabled')) {
            $this->warn('REGWATCH_ENABLED is false; skipping.');

            return self::SUCCESS;
        }

        $query = RegWatchSource::query()
            ->where('active', true)
            ->whereHas('jurisdiction', fn ($q) => $q->where('active', true));

        $slug = $this->option('source');
        if (is_string($slug) && $slug !== '') {
            $query->where('slug', $slug);
        }

        $sources = $query->get();
        if ($sources->isEmpty()) {
            $this->warn('No matching active sources.');

            return self::SUCCESS;
        }

        foreach ($sources as $source) {
            if ($this->option('sync')) {
                $change = $monitor->check($source);
                $this->line(sprintf(
                    '%s: %s',
                    $source->slug,
                    $change ? "change recorded ({$change->id})" : 'no change',
                ));
            } else {
                CheckRegWatchSource::dispatch($source->id);
                $this->line("{$source->slug}: job dispatched");
            }
        }

        $this->info(sprintf('Processed %d source(s).', $sources->count()));

        return self::SUCCESS;
    }
}
