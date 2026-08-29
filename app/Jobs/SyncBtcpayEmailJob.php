<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\BtcPay\BtcpayEmailSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SyncBtcpayEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var bool|null Lazily resolved once per worker process */
    private static ?bool $hasAllowsSatfluxEmailColumn = null;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(
        public int $userId
    ) {}

    public function handle(BtcpayEmailSyncService $emailSync): void
    {
        $user = User::find($this->userId);
        if (! $user || empty($user->email)) {
            return;
        }

        if (self::$hasAllowsSatfluxEmailColumn === null) {
            self::$hasAllowsSatfluxEmailColumn = Schema::hasColumn('users', 'allows_satflux_email_changes');
        }
        if (self::$hasAllowsSatfluxEmailColumn && ! ($user->allows_satflux_email_changes ?? false)) {
            return;
        }

        $emailSync->syncUserEmail($user);
    }

    public function failed(\Throwable $exception): void
    {
        $context = [
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ];

        $user = User::find($this->userId);
        if ($user && filled($user->email)) {
            $email = (string) $user->email;
            $parts = explode('@', $email, 2);
            $context['sync_email_masked'] = count($parts) === 2
                ? (($parts[0] !== '' ? $parts[0][0] : '*').'***@'.$parts[1])
                : '***';
        }

        Log::error('SyncBtcpayEmailJob failed', $context);
    }
}
