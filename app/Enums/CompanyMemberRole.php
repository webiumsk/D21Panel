<?php

namespace App\Enums;

enum CompanyMemberRole: string
{
    /** The company's `user_id` - implicit, never stored as a member row. */
    case Owner = 'owner';

    /** Accounting office: reads and writes documents / expenses, exports, cannot delete the company or touch its credentials. */
    case Accountant = 'accountant';

    /** Any other collaborator with the same rights as an accountant (kept separate for future policy differences). */
    case Member = 'member';

    public function canManageCompany(): bool
    {
        return $this === self::Owner;
    }
}
