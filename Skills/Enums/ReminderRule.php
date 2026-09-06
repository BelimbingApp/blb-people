<?php

namespace App\Domains\People\Skills\Enums;

/**
 * Why somebody is being reminded.
 *
 * Two rules, not one "attention needed", because the remedies differ: an
 * overdue reassessment needs an assessor, an expiring certificate needs a
 * renewal. A single reason would tell a recipient that something is wrong
 * without telling them what to do.
 */
enum ReminderRule: string
{
    case OverdueReassessment = 'overdue_reassessment';
    case ExpiringCertificate = 'expiring_certificate';
}
