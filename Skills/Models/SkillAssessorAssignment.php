<?php

namespace App\Domains\People\Skills\Models;

use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Contracts\ReferencesWorkforceEntities;
use App\Domains\People\Skills\Data\WorkforceReference;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;

final class SkillAssessorAssignment extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_assessor_assignments';

    /** @return list<WorkforceReference> */
    public function workforceReferences(): array
    {
        return [new WorkforceReference('employee_entity_id', WorkforceResourceType::Employee)];
    }

    protected function casts(): array
    {
        return [
            'assessor_user_id' => 'integer',
            'employee_entity_id' => 'integer',
            'assigned_by_user_id' => 'integer',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
        ];
    }

    /** @return array{name: string, id: int} */
    public function getAuditSubject(): array
    {
        return ['name' => 'skill_assessor_assignment', 'id' => (int) $this->getKey()];
    }
}
