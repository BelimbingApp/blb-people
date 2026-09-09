<?php

namespace App\Domains\People\Skills\Enums;

/**
 * Renewal work recorded alongside an employee certification.
 *
 * Expiry is calculated from expires_on. This status describes the renewal
 * workflow, while a successor row records a completed renewal without
 * rewriting the original certificate.
 */
enum CertificationRenewalStatus: string
{
    case Current = 'current';
    case Due = 'due';
    case InProgress = 'in_progress';
    case Renewed = 'renewed';
}
