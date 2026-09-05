<?php

namespace App\Domains\People\Training\Enums;

enum TrainingRequestStatus: string
{
    case Draft = 'draft';
    case PendingHod = 'pending_hod';
    case PendingHr = 'pending_hr';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
