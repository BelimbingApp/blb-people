<?php

namespace App\Domains\People\Skills\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Enums\ReassessmentRequestStatus;
use App\Domains\People\Skills\Exceptions\InvalidReassessmentRequestException;
use App\Domains\People\Skills\Livewire\TeamGaps\Index as TeamGapsIndex;
use App\Domains\People\Skills\Models\SkillReassessmentRequest;
use Illuminate\Support\Facades\DB;

/**
 * HOD reassessment requests for the reassessment loop (0006-b).
 *
 * The employee always comes from the HOD's visible team set, never from the
 * request: naming an employee outside the department is refused, not
 * recorded. One open request per employee and skill; a resolved or
 * cancelled request does not block the next one, so the rule lives here in
 * a transaction rather than in a unique key.
 */
final class SkillReassessmentStore
{
    public const REQUEST = 'people.skill.reassessment.submit';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SkillAudience $audiences,
        private readonly CompanyAttribution $companies,
    ) {}

    public function request(User $actor, int $companyId, int $employeeEntityId, int $skillId, string $reason): SkillReassessmentRequest
    {
        $this->audiences->authorizeAudience($actor, self::REQUEST);
        if (! $this->companies->mayActFor($actor, $companyId)) {
            $this->deny();
        }
        $tenant = $this->tenantContext->requireTenantId();

        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new InvalidReassessmentRequestException('A reassessment reason of up to 1000 characters is required.');
        }

        return DB::transaction(function () use ($actor, $companyId, $employeeEntityId, $skillId, $reason, $tenant): SkillReassessmentRequest {
            // The same seam the gaps page reads: only a report row the HOD
            // can see may be requested, and the HOD's own row is excluded.
            $visible = $this->audiences->visibleEmployeeEntityIdsFor($actor, $companyId, TeamGapsIndex::VIEW_CAPABILITY, includeSelf: false);
            $visible = array_values(array_filter(
                $visible,
                static fn (int $employeeId): bool => $employeeId !== (int) $actor->employee_id,
            ));
            if (! in_array($employeeEntityId, $visible, true)) {
                $this->deny();
            }

            $open = SkillReassessmentRequest::query()->forCompany($tenant, $companyId)
                ->where('employee_entity_id', $employeeEntityId)
                ->where('skill_id', $skillId)
                ->where('status', ReassessmentRequestStatus::Pending->value)
                ->lockForUpdate()
                ->exists();
            if ($open) {
                throw new InvalidReassessmentRequestException('The employee already has an open reassessment request for this skill.');
            }

            return SkillReassessmentRequest::query()->create([
                'tenant_id' => $tenant, 'company_entity_id' => $companyId,
                'employee_entity_id' => $employeeEntityId, 'skill_id' => $skillId,
                'reason' => $reason, 'requested_by_user_id' => $actor->getKey(),
                'due_at' => today()->addDays(30)->toDateString(),
                'status' => ReassessmentRequestStatus::Pending->value,
            ]);
        });
    }

    private function deny(): never
    {
        throw new InvalidReassessmentRequestException('The reassessment request is unavailable in the current scope.');
    }
}
