<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BtcPay\UserService;
use Illuminate\Console\Command;

class ConfirmBtcpayUserEmail extends Command
{
    protected $signature = 'btcpay:confirm-user-email {user : Satflux user id}';

    protected $description = 'Re-confirm a user\'s BTCPay account email via the server API key. '
        .'Recovery for accounts whose email change reset emailConfirmed on BTCPay '
        .'("You must have a confirmed email to log in" on merchant API key calls).';

    public function handle(UserService $userService): int
    {
        $user = User::find((int) $this->argument('user'));
        if (! $user) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        if (empty($user->btcpay_user_id)) {
            $this->error('User has no btcpay_user_id.');

            return self::FAILURE;
        }

        try {
            $userService->confirmUserEmail((string) $user->btcpay_user_id);
        } catch (\Throwable $e) {
            $this->error('BTCPay confirm failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("BTCPay email confirmed for user {$user->id} (btcpay user {$user->btcpay_user_id}).");

        return self::SUCCESS;
    }
}
