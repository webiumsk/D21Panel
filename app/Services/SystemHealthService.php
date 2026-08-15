<?php

namespace App\Services;

use App\Models\WebhookEvent;
use App\Support\ErrorRateCounter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * System health checks (P1 phase 7). Each check returns ok/failed with a
 * short non-sensitive detail and its duration; the artisan command and the
 * admin endpoint (and the phase 8 scheduler) share this service.
 */
class SystemHealthService
{
    public const CHECKS = ['database', 'queue', 'btcpay', 'relay', 'disk', 'webhooks', 'errors'];

    /** Free disk space below this fails the disk check. */
    public const MIN_FREE_DISK_BYTES = 500 * 1024 * 1024;

    /** More failed jobs than this within the window fails the queue check. */
    public const MAX_FAILED_JOBS = 10;

    /**
     * Only failures inside this window count against the threshold - an
     * all-time count keeps the check red forever over historical rows
     * that were already investigated (flush is not required to go green).
     */
    public const FAILED_JOBS_WINDOW_HOURS = 24;

    /** No processed webhook for this long (with webhooks configured) is suspicious. */
    public const WEBHOOK_STALE_HOURS = 48;

    /**
     * @return array<string, array{ok: bool, detail: string, duration_ms: int}>
     */
    public function runChecks(): array
    {
        $results = [];
        foreach (self::CHECKS as $check) {
            $startedAt = hrtime(true);
            try {
                $results[$check] = $this->{'check'.ucfirst($check)}();
            } catch (\Throwable $e) {
                $results[$check] = ['ok' => false, 'detail' => $e->getMessage()];
            }
            $results[$check]['duration_ms'] = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        }

        return $results;
    }

    /**
     * @param  array<string, array{ok: bool, detail: string, duration_ms: int}>  $results
     */
    public function allHealthy(array $results): bool
    {
        foreach ($results as $result) {
            if (! $result['ok']) {
                return false;
            }
        }

        return true;
    }

    /** @return array{ok: bool, detail: string} */
    protected function checkDatabase(): array
    {
        DB::select('select 1');

        return ['ok' => true, 'detail' => 'connected'];
    }

    /** @return array{ok: bool, detail: string} */
    protected function checkQueue(): array
    {
        $recent = (int) DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subHours(self::FAILED_JOBS_WINDOW_HOURS))
            ->count();
        $total = (int) DB::table('failed_jobs')->count();

        return [
            'ok' => $recent <= self::MAX_FAILED_JOBS,
            'detail' => "{$recent} failed job(s) in ".self::FAILED_JOBS_WINDOW_HOURS."h ({$total} total)",
        ];
    }

    /** @return array{ok: bool, detail: string} */
    protected function checkBtcpay(): array
    {
        $baseUrl = rtrim((string) config('services.btcpay.base_url'), '/');
        if ($baseUrl === '') {
            return ['ok' => false, 'detail' => 'BTCPAY_BASE_URL not configured'];
        }

        $response = Http::timeout(5)->get($baseUrl.'/api/v1/health');

        return [
            'ok' => $response->successful(),
            'detail' => 'HTTP '.$response->status(),
        ];
    }

    /** @return array{ok: bool, detail: string} */
    protected function checkRelay(): array
    {
        $relayUrl = trim((string) config('security.csp.evolu_relay_url'));
        if ($relayUrl === '') {
            return ['ok' => true, 'detail' => 'CSP_EVOLU_RELAY_URL not set - relay check skipped'];
        }

        $httpUrl = preg_replace(['/^wss:/i', '/^ws:/i'], ['https:', 'http:'], $relayUrl) ?? $relayUrl;
        // ANY HTTP response proves the relay host is up (mirrors the client probe).
        $response = Http::timeout(5)->get(rtrim($httpUrl, '/').'/usage/probe');

        return ['ok' => true, 'detail' => 'HTTP '.$response->status()];
    }

    /** @return array{ok: bool, detail: string} */
    protected function checkDisk(): array
    {
        $free = disk_free_space(storage_path());
        if ($free === false) {
            return ['ok' => false, 'detail' => 'disk_free_space unavailable'];
        }
        $freeMb = (int) round($free / (1024 * 1024));

        return [
            'ok' => $free >= self::MIN_FREE_DISK_BYTES,
            'detail' => "{$freeMb} MB free",
        ];
    }

    /** @return array{ok: bool, detail: string} */
    protected function checkErrors(): array
    {
        $count = ErrorRateCounter::currentHourCount();
        if ($count === null) {
            return ['ok' => false, 'detail' => 'error counter unavailable (cache read failed)'];
        }
        $threshold = (int) config('monitoring.error_rate_threshold', 25);

        return [
            'ok' => $count < $threshold,
            'detail' => "{$count} error(s) this hour (threshold {$threshold})",
        ];
    }

    /** @return array{ok: bool, detail: string} */
    protected function checkWebhooks(): array
    {
        $latest = WebhookEvent::query()->latest('created_at')->value('created_at');
        if ($latest === null) {
            return ['ok' => true, 'detail' => 'no webhook events yet'];
        }

        $ageHours = now()->diffInHours($latest, true);

        return [
            'ok' => $ageHours <= self::WEBHOOK_STALE_HOURS,
            'detail' => 'last event '.round($ageHours, 1).'h ago',
        ];
    }
}
