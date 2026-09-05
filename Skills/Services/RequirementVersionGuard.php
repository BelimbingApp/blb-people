<?php

namespace App\Domains\People\Skills\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Skills\Contracts\ConfirmsAssessableRequirementVersion;
use App\Domains\People\Skills\Enums\RequirementProfileStatus;
use App\Domains\People\Skills\Exceptions\InvalidAssessmentException;
use App\Domains\People\Skills\Models\RequirementProfile;

/**
 * The profile side's answer to "may an assessment be taken against this
 * version".
 *
 * Draft and retired are refused for different reasons that land in the same
 * place. A draft is a proposal nobody has approved. A retired version is still
 * the correct answer for a date when it applied, and reading that evidence does
 * not authorise creating new evidence against it — see
 * docs/contracts/requirement-versioning.md.
 *
 * The company check is not redundant with the tenant scope. Two companies in
 * one tenant each publish their own policy, and pinning an assessment to the
 * other one's version would attribute one company's requirements to another's
 * people while looking entirely valid.
 */
final class RequirementVersionGuard implements ConfirmsAssessableRequirementVersion
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function assertAssessable(int $companyEntityId, int $requirementProfileId): void
    {
        $profile = RequirementProfile::query()
            ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)
            ->whereKey($requirementProfileId)
            ->first();

        if ($profile === null) {
            throw new InvalidAssessmentException(
                "Requirement profile version {$requirementProfileId} is not one of this company's, so an assessment cannot be taken against it.",
            );
        }

        if ($profile->status !== RequirementProfileStatus::Published) {
            throw new InvalidAssessmentException(
                "Requirement profile version {$requirementProfileId} is {$profile->status->value}; only a published version can be assessed against.",
            );
        }
    }
}
