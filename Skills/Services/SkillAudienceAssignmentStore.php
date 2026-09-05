<?php

namespace App\Domains\People\Skills\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Exceptions\InvalidSkillAudienceAssignmentException;
use App\Domains\People\Skills\Models\SkillActorBinding;
use App\Domains\People\Skills\Models\SkillAssessorAssignment;
use Illuminate\Support\Facades\DB;

/** Reviewed mutation boundary for identity bindings and assessor assignments. */
final class SkillAudienceAssignmentStore
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CompanyAttribution $companies,
        private readonly SkillAudience $audience,
        private readonly WorkforceSubjects $workforce,
    ) {}

    public function confirmActor(
        User $confirmedBy,
        User $platformUser,
        int $companyEntityId,
        int $employeeEntityId,
        string $reviewReference,
    ): SkillActorBinding {
        $reviewReference = trim($reviewReference);
        if ($reviewReference === '') {
            throw new InvalidSkillAudienceAssignmentException('Actor bindings require a review reference.');
        }

        $this->audience->assertHr($confirmedBy, $companyEntityId);
        if (! $this->companies->mayActFor($platformUser, $companyEntityId)) {
            throw new InvalidSkillAudienceAssignmentException('The platform user is outside the workforce company boundary.');
        }

        $tenantId = $this->tenantContext->requireTenantId();
        $employee = $this->workforce->employeeForUser(
            $companyEntityId,
            (int) $platformUser->getAuthIdentifier(),
        );

        if ($employee === null
            || $employee->reference->externalId !== (string) $employeeEntityId) {
            throw new InvalidSkillAudienceAssignmentException('Actor bindings require an active reviewed employee and user relationship.');
        }

        return DB::transaction(function () use ($tenantId, $companyEntityId, $platformUser, $employee, $confirmedBy, $reviewReference): SkillActorBinding {
            return SkillActorBinding::query()->updateOrCreate([
                'tenant_id' => $tenantId,
                'company_entity_id' => $companyEntityId,
                'platform_user_id' => $platformUser->getAuthIdentifier(),
            ], [
                'employee_entity_id' => (int) $employee->reference->externalId,
                'user_entity_id' => $platformUser->getAuthIdentifier(),
                'confirmed_by_user_id' => $confirmedBy->getAuthIdentifier(),
                'review_reference' => $reviewReference,
                'confirmed_at' => now(),
                'revoked_at' => null,
                'revoked_by_user_id' => null,
                'revocation_reference' => null,
            ]);
        });
    }

    public function revokeActor(
        User $revokedBy,
        int $companyEntityId,
        int $platformUserId,
        string $reviewReference,
    ): void {
        $reviewReference = trim($reviewReference);
        if ($reviewReference === '') {
            throw new InvalidSkillAudienceAssignmentException('Actor-binding revocation requires a review reference.');
        }

        $this->audience->assertHr($revokedBy, $companyEntityId);
        $binding = SkillActorBinding::query()
            ->forCompany($this->tenantContext->requireTenantId(), $companyEntityId)
            ->where('platform_user_id', $platformUserId)
            ->whereNull('revoked_at')
            ->first();

        if ($binding === null) {
            return;
        }

        $binding->revoked_at = now();
        $binding->revoked_by_user_id = $revokedBy->getAuthIdentifier();
        $binding->revocation_reference = $reviewReference;
        $binding->save();
    }

    public function assignAssessor(
        User $assignedBy,
        User $assessor,
        int $companyEntityId,
        int $employeeEntityId,
        string $reviewReference,
        ?\DateTimeInterface $effectiveTo = null,
    ): SkillAssessorAssignment {
        $reviewReference = trim($reviewReference);
        if ($reviewReference === '') {
            throw new InvalidSkillAudienceAssignmentException('Assessor assignments require a review reference.');
        }

        $this->audience->assertHr($assignedBy, $companyEntityId);
        if (! $this->companies->mayActFor($assessor, $companyEntityId)) {
            throw new InvalidSkillAudienceAssignmentException('The assessor is outside the workforce company boundary.');
        }

        $tenantId = $this->tenantContext->requireTenantId();
        if (! collect($this->workforce->employees($companyEntityId))->contains(
            fn ($employee): bool => $employee->reference->externalId === (string) $employeeEntityId,
        )) {
            throw new InvalidSkillAudienceAssignmentException('The assessed employee is outside the workforce company boundary.');
        }

        return SkillAssessorAssignment::query()->updateOrCreate([
            'tenant_id' => $tenantId,
            'company_entity_id' => $companyEntityId,
            'assessor_user_id' => $assessor->getAuthIdentifier(),
            'employee_entity_id' => $employeeEntityId,
        ], [
            'assigned_by_user_id' => $assignedBy->getAuthIdentifier(),
            'review_reference' => $reviewReference,
            'effective_from' => now(),
            'effective_to' => $effectiveTo,
        ]);
    }
}
