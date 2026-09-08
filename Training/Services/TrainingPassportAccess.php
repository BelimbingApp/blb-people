<?php

namespace App\Domains\People\Training\Services;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Provider\Exceptions\WorkforceProjectionException;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Exceptions\TrainingPassportDenied;
use Illuminate\Contracts\Auth\Authenticatable;

final class TrainingPassportAccess
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SkillAudience $audience,
    ) {}

    public function ownSubject(User $actor): WorkforceSubject
    {
        $tenantId = $this->tenantContext->currentTenantId();
        $companyId = $actor->company_id;
        $employeeId = $actor->employee_id;

        if ($tenantId === null
            || $actor->tenant_id !== $tenantId
            || $companyId === null
            || $employeeId === null) {
            $this->deny();
        }

        $subject = new WorkforceSubject(
            $tenantId,
            (int) $companyId,
            WorkforceResourceType::Employee,
            (string) $employeeId,
            new ExternalReference(WorkforceResourceType::Employee, (string) $employeeId),
        );
        $this->authorize($actor, $subject);

        return $subject;
    }

    public function authorize(User $actor, WorkforceSubject $subject): void
    {
        $tenantId = $this->tenantContext->currentTenantId();
        if ($tenantId === null
            || $subject->tenantId !== $tenantId
            || $subject->companyId === null
            || $actor->tenant_id !== $tenantId
            || $actor->company_id !== $subject->companyId
            || $subject->type !== WorkforceResourceType::Employee
            || ! ctype_digit($subject->stableId)) {
            $this->deny();
        }

        try {
            $visible = $this->audience->visibleEmployeeEntityIdsFor(
                $actor,
                (int) $subject->companyId,
                TrainingPassportReader::VIEW_CAPABILITY,
                includeSelf: true,
            );
        } catch (AuthorizationDeniedException) {
            $this->deny();
        } catch (WorkforceProjectionException) {
            // The provider directory is down: the tenant, company, type and
            // capability checks above still hold, so the passport stays
            // readable instead of failing — but the directory-derived
            // visibility refinement cannot run, and the reader marks the
            // workforce context unavailable. (0014-d)
            return;
        }

        if (! in_array((int) $subject->stableId, $visible, true)) {
            $this->deny();
        }
    }

    public function mayViewOwn(Authenticatable $actor): bool
    {
        if (! $actor instanceof User) {
            return false;
        }

        try {
            $this->ownSubject($actor);

            return true;
        } catch (TrainingPassportDenied) {
            return false;
        }
    }

    private function deny(): never
    {
        throw new TrainingPassportDenied('The training passport is unavailable in the current scope.');
    }
}
