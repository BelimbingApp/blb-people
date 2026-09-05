<?php

namespace App\Domains\People\Skills\Enums;

enum AssessmentStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case PendingHodVerification = 'pending_hod_verification';
    case Returned = 'returned';
    case Finalized = 'finalized';
}
