<?php

namespace App\Domains\People\Skills\Enums;

/**
 * Why somebody is being reminded.
 *
 * Three rules, not one "attention needed", because the remedies differ: an
 * overdue reassessment needs an assessor, an expiring certificate needs a
 * renewal, an overdue development action needs the HOD to remove the blocker
 * or reset an accountable plan at the monthly review. A single reason would
 * tell a recipient that something is wrong without telling them what to do.
 */
enum ReminderRule: string
{
    case OverdueReassessment = 'overdue_reassessment';
    case ExpiringCertificate = 'expiring_certificate';
    case OverdueDevelopmentAction = 'overdue_development_action';
}
