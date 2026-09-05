<?php

namespace App\Notifications;

use App\Models\EmailVerificationChallenge;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Carries the plaintext 6-digit code to the target inbox. Sent on-demand
 * (Notification::route('mail', ...)) because the code often goes to an
 * address that is not yet the user's account email. Sent inline (not
 * queued) like VerifyEmailNotification so a mail failure surfaces to the
 * request that asked for the code.
 */
class EmailCodeNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $purpose,
        public readonly int $ttlMinutes,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->code.' is your satflux.io verification code');

        if ($this->purpose === EmailVerificationChallenge::PURPOSE_WALLET_CONNECTION_CHANGE) {
            $mail->line('Someone asked to change the wallet connection of your satflux.io store. Enter this code in satflux.io to continue:');
        } else {
            $mail->line('Enter this code in satflux.io to confirm your email address:');
        }

        return $mail
            ->line('**'.$this->code.'**')
            ->line("The code expires in {$this->ttlMinutes} minutes and works only once.")
            ->line('If you did not request this, you can ignore this email - nothing changes without the code.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['purpose' => $this->purpose];
    }
}
