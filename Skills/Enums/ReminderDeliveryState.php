<?php

namespace App\Domains\People\Skills\Enums;

/**
 * Where one reminder delivery stands. Two states only: a row exists from the
 * moment a delivery is claimed, so "not yet sent" is a failed row whose
 * failure says why, never a missing row.
 */
enum ReminderDeliveryState: string
{
    case Sent = 'sent';
    case Failed = 'failed';
}
