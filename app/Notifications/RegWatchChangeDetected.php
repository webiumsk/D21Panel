<?php

namespace App\Notifications;

use App\Models\RegWatchChange;
use App\Models\RegWatchSource;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the configured REGWATCH_NOTIFY_EMAIL when the monitoring cron
 * detects a change on an official source (docs/LEGAL.md). The change awaits
 * human review - the mail deliberately carries the classifier summary only,
 * never rule content.
 */
class RegWatchChangeDetected extends Notification
{
    public function __construct(
        public RegWatchSource $source,
        public RegWatchChange $change,
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
            ->subject("RegWatch: change detected - {$this->source->name}")
            ->line("A monitored source changed and awaits review (status: {$this->change->status->value}).")
            ->line("**Source:** {$this->source->name}")
            ->line("**URL:** {$this->source->url}")
            ->line('**Detected:** '.$this->change->detected_at->toDateTimeString());

        if ($this->change->summary !== null && $this->change->summary !== '') {
            $mail->line('**Classifier summary:** '.$this->escapeMarkdown($this->change->summary));
        }

        return $mail
            ->line('Review the diff and update the affected rule manually after verifying the official source.')
            ->line('Orientačné - overte s lokálnym odborníkom.');
    }

    /**
     * The summary is LLM-derived text rendered into a Markdown mail - escape
     * control characters so it can only ever read as plain text.
     */
    private function escapeMarkdown(string $text): string
    {
        return str_replace(
            ['\\', '`', '[', ']', '(', ')', '*', '_', '#'],
            ['\\\\', '\\`', '\\[', '\\]', '\\(', '\\)', '\\*', '\\_', '\\#'],
            $text,
        );
    }
}
