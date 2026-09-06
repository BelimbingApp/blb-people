<?php

namespace App\Domains\People\Performance\Enums;

/**
 * Why a review is being chased. Kept apart because they are different silences
 * with different owners: a stale draft is the manager's to finish, a pending
 * response is the employee's to give.
 */
enum OverdueReviewReason: string
{
    case StaleDraft = 'stale_draft';
    case ResponsePending = 'response_pending';

    public function label(): string
    {
        return match ($this) {
            self::StaleDraft => 'Draft not finalized',
            self::ResponsePending => 'Employee response pending',
        };
    }
}
