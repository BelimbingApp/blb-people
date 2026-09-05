<?php

namespace App\Domains\People\Skills\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Organisation\Contracts\SummarizesOrganisationSkillCoverage;
use App\Domains\People\Organisation\Data\OrganisationIndicatorValue;
use App\Domains\People\Skills\Contracts\ResolvesSkillRequirements;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use DateTimeInterface;

final class OrganisationSkillCoverage implements SummarizesOrganisationSkillCoverage
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ResolvesSkillRequirements $requirements,
    ) {}

    public function summarize(
        string $companyStableId,
        array $employees,
        DateTimeInterface $asOf,
    ): OrganisationIndicatorValue {
        $tenantId = $this->tenantContext->requireTenantId();

        if (! ctype_digit($companyStableId)) {
            return new OrganisationIndicatorValue(null, count($employees), true);
        }

        $required = [];

        foreach ($employees as $employee) {
            if (! ctype_digit($employee->reference->externalId)) {
                return new OrganisationIndicatorValue(null, count($employees), true);
            }

            $employeeId = (int) $employee->reference->externalId;
            $required[$employeeId] = $this->requirements->requirementsFor([
                'company_entity_id' => (int) $companyStableId,
                'department_entity_id' => $this->numeric($employee->organizationReference?->externalId),
                'position_entity_id' => $this->numeric($employee->positionReference?->externalId),
                'employee_entity_id' => $employeeId,
            ], $asOf);
        }

        $scores = EmployeeSkillScore::query()
            ->forCompany($tenantId, (int) $companyStableId)
            ->whereIn('employee_entity_id', array_keys($required))
            ->whereRaw('(valid_until IS NULL OR DATE(valid_until) >= ?)', [$asOf->format('Y-m-d')])
            ->get()
            ->keyBy(fn (EmployeeSkillScore $score): string => $score->employee_entity_id.':'.$score->skill_id);
        $covered = 0;
        $total = 0;

        foreach ($required as $employeeId => $requirements) {
            foreach ($requirements as $requirement) {
                $total++;
                $score = $scores->get($employeeId.':'.$requirement->skillId);
                $covered += (int) ($score !== null
                    && $score->requirement_reference === $requirement->requirementReference
                    && $score->requirement_version === $requirement->requirementVersion
                    && $score->current_level >= $requirement->requiredLevel);
            }
        }

        return new OrganisationIndicatorValue(
            value: $total === 0 ? null : (int) round(($covered / $total) * 100),
            cohortSize: count($employees),
            incomplete: $total === 0,
        );
    }

    private function numeric(?string $value): ?int
    {
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
