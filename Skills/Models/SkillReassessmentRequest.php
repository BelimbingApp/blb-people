<?php

namespace App\Domains\People\Skills\Models;

use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Contracts\ReferencesWorkforceEntities;
use App\Domains\People\Skills\Data\WorkforceReference;
use App\Domains\People\Skills\Enums\ReassessmentRequestStatus;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;

final class SkillReassessmentRequest extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_reassessment_requests';

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
            'due_at' => 'date',
            'status' => ReassessmentRequestStatus::class,
        ];
    }

    public function isOpen(): bool
    {
        return $this->status instanceof ReassessmentRequestStatus
            ? $this->status->isOpen()
            : $this->status === ReassessmentRequestStatus::Pending->value;
    }
}
