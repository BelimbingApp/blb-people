<?php

namespace App\Domains\People\Progression\Enums;

enum ProgressionPolicyRefusal: string
{
    case MissingTenant = 'missing_tenant';
    case TenantMismatch = 'tenant_mismatch';
    case WrongCompany = 'wrong_company';
    case UnknownSubject = 'unknown_subject';
    case DeactivatedSubject = 'deactivated_subject';
    case NoPolicyPublished = 'no_policy_published';
    case InvalidPolicy = 'invalid_policy';
}
