<?php

namespace App\Enums;

/**
 * Review workflow of a detected legislative change (docs/LEGAL.md):
 * the cron inserts New rows; a human moves them to Reviewed and then
 * Applied (rule updated) or Dismissed (not relevant).
 */
enum RegWatchChangeStatus: string
{
    case New = 'new';
    case Reviewed = 'reviewed';
    case Applied = 'applied';
    case Dismissed = 'dismissed';

    /**
     * Statuses this one may move to. Applying requires passing through
     * Reviewed; obvious noise may be dismissed straight from New. Applied
     * and Dismissed are terminal.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::Reviewed, self::Dismissed],
            self::Reviewed => [self::Applied, self::Dismissed],
            self::Applied, self::Dismissed => [],
        };
    }
}
