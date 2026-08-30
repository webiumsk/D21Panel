<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BtcPay\UserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class HealUnconfirmedBtcpayUsers extends Command
{
    protected $signature = 'btcpay:heal-unconfirmed-users {--dry-run : Only report, do not confirm}';

    protected $description = 'Find satflux-managed BTCPay users whose email is unconfirmed and re-confirm them '
        .'via the EmailConfirm plugin endpoint. Heals accounts locked out by the '
        .'"confirmed email required to log in" policy after an email change.';

    public function handle(UserService $userService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $checked = 0;
        $healed = 0;
        $failed = 0;

        User::query()
            ->whereNotNull('btcpay_user_id')
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($userService, $dryRun, &$checked, &$healed, &$failed) {
                foreach ($users as $user) {
                    $checked++;

                    try {
                        $btcpayUser = $userService->getUser((string) $user->btcpay_user_id);
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->warn("  user {$user->id}: lookup failed ({$e->getMessage()})");

                        continue;
                    }

                    if (! empty($btcpayUser['emailConfirmed'])) {
                        continue;
                    }

                    if ($dryRun) {
                        $healed++;
                        $this->line("  user {$user->id} (btcpay {$user->btcpay_user_id}): UNCONFIRMED - would confirm");

                        continue;
                    }

                    try {
                        $userService->confirmUserEmail((string) $user->btcpay_user_id);
                        $healed++;
                        $this->info("  user {$user->id} (btcpay {$user->btcpay_user_id}): confirmed");
                        Log::info('Healed unconfirmed BTCPay user email', [
                            'user_id' => $user->id,
                            'btcpay_user_id' => $user->btcpay_user_id,
                        ]);
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->warn("  user {$user->id}: confirm failed ({$e->getMessage()})");
                        Log::warning('BTCPay unconfirmed-user heal failed', [
                            'user_id' => $user->id,
                            'btcpay_user_id' => $user->btcpay_user_id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $verb = $dryRun ? 'would confirm' : 'confirmed';
        $this->info("Checked {$checked} users, {$verb} {$healed}, failed {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
