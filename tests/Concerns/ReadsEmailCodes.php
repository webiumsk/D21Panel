<?php

namespace Tests\Concerns;

use App\Notifications\EmailCodeNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/**
 * Reads the plaintext 6-digit code out of the faked on-demand
 * EmailCodeNotification (Notification::fake() must be active).
 */
trait ReadsEmailCodes
{
    /** The most recently sent code, optionally filtered by recipient address. */
    protected function lastEmailCode(?string $email = null): string
    {
        $code = null;

        Notification::assertSentOnDemand(
            EmailCodeNotification::class,
            function (EmailCodeNotification $notification, array $channels, AnonymousNotifiable $notifiable) use (&$code, $email) {
                if ($email !== null && ($notifiable->routes['mail'] ?? null) !== $email) {
                    return false;
                }
                $code = $notification->code;

                return true;
            }
        );

        $this->assertNotNull($code, 'No EmailCodeNotification was sent.');

        return $code;
    }

    protected function wrongEmailCode(string $code): string
    {
        return $code === '000000' ? '000001' : '000000';
    }
}
