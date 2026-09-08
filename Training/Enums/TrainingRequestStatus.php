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

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::PendingHod => __('Pending HOD'),
            self::PendingHr => __('Pending HR'),
            self::PendingApproval => __('Pending approval'),
            self::Approved => __('Approved'),
            self::Rejected => __('Rejected'),
            self::Cancelled => __('Cancelled'),
        };
    }
}
