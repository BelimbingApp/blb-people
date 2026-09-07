<?php

namespace App\Domains\People\Skills\Models;

use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Contracts\ReferencesWorkforceEntities;
use App\Domains\People\Skills\Data\WorkforceReference;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;

/**
 * Current valid skill level for an employee, projected from finalized assessment history.
 * Never overwrite by mutating a finalized assessment — only via a new finalized source row.
 */
class EmployeeSkillScore extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_employee_scores';

    public function companyOwnerColumn(): ?string
    {
        return 'company_entity_id';
    }

    /** @return list<WorkforceReference> */
    public function workforceReferences(): array
    {
        return [
            new WorkforceReference('employee_entity_id', WorkforceResourceType::Employee),
        ];
    }

    protected function casts(): array
    {
        return [
            'requirement_version' => 'integer',
            'required_level' => 'integer',
            'current_level' => 'integer',
            'gap' => 'integer',
            'mandatory_gate' => 'boolean',
            'criticality' => RequirementCriticality::class,
            'assessed_at' => 'datetime',
            'next_assessment_due' => 'date',
            'valid_until' => 'date',
        ];
    }

    /**
     * Whether this score is somebody you can count on for the requirement it
     * measures: at or above the level asked for, and not lapsed. Callers
     * with a bar of their own — a department's strictest requirement, say —
     * pass it; otherwise the score answers for its own requirement.
     *
     * A score that has lapsed is a record of past competence — a certificate
     * that expired last month is not somebody you can call at 2am. Both the
     * backup coverage page (0007-b) and the per-department coverage service
     * (0007-c) ask this question, so it is answered once, here.
     */
    public function coversRequirement(?string $asOf = null, ?int $requiredLevel = null): bool
    {
        $asOf ??= now()->toDateString();
        $requiredLevel ??= (int) $this->required_level;

        return (int) $this->current_level >= $requiredLevel
            && ($this->valid_until === null || $this->valid_until->toDateString() >= $asOf);
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'employee_skill_score', 'id' => $this->getKey()];
    }
}
