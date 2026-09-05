<?php

namespace App\Domains\People\Skills\Models;

use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Contracts\ReferencesWorkforceEntities;
use App\Domains\People\Skills\Data\WorkforceReference;
use App\Domains\People\Skills\Enums\DevelopmentActionClosure;
use App\Domains\People\Skills\Enums\DevelopmentActionStatus;
use App\Domains\People\Skills\Enums\DevelopmentActionType;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use Carbon\CarbonInterface;

final class DevelopmentAction extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_development_actions';

    public function companyOwnerColumn(): ?string
    {
        return 'company_entity_id';
    }

    /** @return list<WorkforceReference> */
    public function workforceReferences(): array
    {
        return [
            new WorkforceReference('employee_entity_id', WorkforceResourceType::Employee),
            new WorkforceReference('owner_employee_entity_id', WorkforceResourceType::Employee),
            new WorkforceReference('hr_coordinator_employee_entity_id', WorkforceResourceType::Employee),
            new WorkforceReference('trainer_employee_entity_id', WorkforceResourceType::Employee),
        ];
    }

    protected function casts(): array
    {
        return [
            'starting_level' => 'integer',
            'target_level' => 'integer',
            'gap_at_start' => 'integer',
            'criticality' => RequirementCriticality::class,
            'mandatory_gate' => 'boolean',
            'priority_score' => 'integer',
            'action_type' => DevelopmentActionType::class,
            'status' => DevelopmentActionStatus::class,
            'closure_status' => DevelopmentActionClosure::class,
            'start_date' => 'date',
            'due_date' => 'date',
            'approved_at' => 'datetime',
            'completed_at' => 'datetime',
            'reassessment_due' => 'date',
            'post_level' => 'integer',
        ];
    }

    public function daysOverdue(?CarbonInterface $today = null): int
    {
        if (! $this->status->isOpen() || $this->due_date === null) {
            return 0;
        }

        $today ??= now();

        return max($this->due_date->startOfDay()->diffInDays($today->startOfDay(), false), 0);
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'development_action', 'id' => $this->getKey()];
    }
}
