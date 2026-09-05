<?php

namespace App\Domains\People\Skills\Models;

use App\Domains\People\Skills\Contracts\ReferencesWorkforceEntities;
use App\Domains\People\Skills\Data\WorkforceReference;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Skills\Enums\RequirementCriticality;

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

    public function getAuditSubject(): ?array
    {
        return ['name' => 'employee_skill_score', 'id' => $this->getKey()];
    }
}
