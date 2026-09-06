<?php

namespace App\Domains\People\Organisation\Models;

use App\Domains\People\Organisation\Enums\PositionAssignmentType;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Contracts\ReferencesWorkforceEntities;
use App\Domains\People\Skills\Data\WorkforceReference;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;

final class PositionAssignment extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    protected $table = 'people_position_assignments';

    /** @return list<WorkforceReference> */
    public function workforceReferences(): array
    {
        return [new WorkforceReference('employee_entity_id', WorkforceResourceType::Employee)];
    }

    protected function casts(): array
    {
        return [
            'type' => PositionAssignmentType::class,
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
        ];
    }
}
