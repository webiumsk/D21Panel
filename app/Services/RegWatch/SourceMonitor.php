<?php

namespace App\Services\RegWatch;

use App\Enums\RegWatchChangeStatus;
use App\Models\RegWatchChange;
use App\Models\RegWatchSource;
use App\Notifications\RegWatchChangeDetected;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * RegWatch monitoring core (docs/LEGAL.md): fetch a source, normalize it,
 * diff against the stored snapshot and record a detection in
 * regwatch_changes (status 'new') for human review. This pipeline NEVER
 * writes to regwatch_rules - rules are edited only by a human after review.
 */
class SourceMonitor
{
    public function __construct(
        private readonly DiffClassifier $classifier,
    ) {}

    /**
     * Check one source. Returns the created change row, or null when
     * nothing changed (or the fetch failed / first-run baseline was stored).
     * Serialized per source via a cache lock so concurrent runs (queue retry
     * racing a --sync run) cannot double-detect.
     */
    public function check(RegWatchSource $source): ?RegWatchChange
    {
        $lock = Cache::lock("regwatch:check-source:{$source->id}", 120);
        if (! $lock->get()) {
            Log::info('[regwatch] check already running, skipped', ['source' => $source->slug]);

            return null;
        }

        try {
            return $this->runCheck($source);
        } finally {
            $lock->release();
        }
    }

    private function runCheck(RegWatchSource $source): ?RegWatchChange
    {
        $body = $this->fetch($source);
        if ($body === null) {
            return null;
        }

        $normalized = $this->normalize($body);
        $hash = hash('sha256', $normalized);

        if ($hash === $source->last_snapshot_hash) {
            $source->forceFill(['last_checked_at' => now()])->save();

            return null;
        }

        if ($source->last_snapshot_hash === null) {
            // First run: store the baseline silently - there is nothing to
            // diff against yet, and a "whole page added" change is noise.
            $this->writeSnapshot($source, $normalized);
            $this->advance($source, $hash);
            Log::info('[regwatch] baseline snapshot stored', ['source' => $source->slug]);

            return null;
        }

        $previous = $this->readSnapshot($source);
        $classification = null;

        if ($previous === null) {
            // A hash exists but the snapshot file is gone (storage wiped) -
            // that is a broken-storage condition, not a first run. Record the
            // detection anyway so a real change is never silently swallowed.
            Log::warning('[regwatch] previous snapshot missing - diff unavailable', [
                'source' => $source->slug,
            ]);
            $diff = '[previous snapshot missing - diff unavailable]';
        } else {
            $maxChars = (int) config('regwatch.max_diff_chars', 20000);
            $diff = SnapshotDiff::diff($previous, $normalized, $maxChars);
            if ($diff === '') {
                // Hash differs but no line-level change (e.g. whitespace only).
                $this->writeSnapshot($source, $normalized);
                $this->advance($source, $hash);

                return null;
            }
            $classification = $this->classifier->classify($source, $diff);
        }

        // The change row and the hash advance commit together: a failed
        // insert rolls the hash back so a retry re-detects, and a committed
        // insert can never be produced twice for the same content.
        $change = DB::transaction(function () use ($source, $hash, $diff, $classification) {
            $change = RegWatchChange::create([
                'source_id' => $source->id,
                'status' => RegWatchChangeStatus::New,
                'summary' => $classification !== null && $classification['summary'] !== ''
                    ? $classification['summary']
                    : null,
                'diff' => $diff,
                'classification_json' => $classification,
                'detected_at' => now(),
            ]);
            $this->advance($source, $hash);

            return $change;
        });

        // Snapshot file only after the commit - if the insert had failed, the
        // old snapshot must survive for the retry's diff.
        $this->writeSnapshot($source, $normalized);

        $this->notify($source, $change);

        return $change;
    }

    private function advance(RegWatchSource $source, string $hash): void
    {
        $source->forceFill([
            'last_checked_at' => now(),
            'last_snapshot_hash' => $hash,
        ])->save();
    }

    private function fetch(RegWatchSource $source): ?string
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'satflux-regwatch/1.0'])
                ->timeout((int) config('regwatch.http_timeout', 30))
                ->get($source->url);

            if (! $response->successful()) {
                Log::warning('[regwatch] source fetch failed', [
                    'source' => $source->slug,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->body();
        } catch (\Throwable $e) {
            Log::warning('[regwatch] source fetch failed', [
                'source' => $source->slug,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Reduce an HTML page to comparable text: drop script/style blocks
     * (deploy-hashed bundles would flag a change on every release), turn
     * block-element boundaries into line breaks (minified HTML would
     * otherwise collapse into one giant line and diff as a whole), strip
     * tags and collapse whitespace line by line.
     */
    private function normalize(string $html): string
    {
        $text = preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = preg_replace('/<!--.*?-->/s', ' ', $text) ?? $text;
        $text = preg_replace(
            '/<\/?(p|div|li|ul|ol|dl|dt|dd|tr|td|th|table|thead|tbody|tfoot|h[1-6]|section|article|aside|header|footer|nav|main|blockquote|pre|form|fieldset)\b[^>]*>|<br\s*\/?\s*>/i',
            "\n",
            $text,
        ) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = preg_split('/\R/u', $text) ?: [];
        $lines = array_filter(array_map(
            fn (string $line) => trim(preg_replace('/\s+/u', ' ', $line) ?? $line),
            $lines,
        ), fn (string $line) => $line !== '');

        return implode("\n", $lines);
    }

    private function snapshotPath(RegWatchSource $source): string
    {
        return trim((string) config('regwatch.snapshot_dir'), '/')."/{$source->slug}.txt";
    }

    private function readSnapshot(RegWatchSource $source): ?string
    {
        $disk = Storage::disk((string) config('regwatch.snapshot_disk', 'local'));
        $path = $this->snapshotPath($source);

        return $disk->exists($path) ? $disk->get($path) : null;
    }

    private function writeSnapshot(RegWatchSource $source, string $normalized): void
    {
        Storage::disk((string) config('regwatch.snapshot_disk', 'local'))
            ->put($this->snapshotPath($source), $normalized);
    }

    private function notify(RegWatchSource $source, RegWatchChange $change): void
    {
        $email = (string) config('regwatch.notify_email');
        if ($email === '') {
            Log::info('[regwatch] change detected (no notify_email configured)', [
                'source' => $source->slug,
                'change_id' => $change->id,
            ]);

            return;
        }

        try {
            Notification::route('mail', $email)
                ->notify(new RegWatchChangeDetected($source, $change));
        } catch (\Throwable $e) {
            Log::warning('[regwatch] notification failed', [
                'source' => $source->slug,
                'change_id' => $change->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
