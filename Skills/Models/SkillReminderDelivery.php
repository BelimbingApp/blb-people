<?php

namespace App\Domains\People\Skills\Models;

use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Contracts\ReferencesWorkforceEntities;
use App\Domains\People\Skills\Data\WorkforceReference;
use App\Domains\People\Skills\Enums\ReminderDeliveryState;
use App\Domains\People\Skills\Enums\ReminderRule;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;

/**
 * One reminder, to one recipient, in one period: sent, or failed and why.
 * The period is the ISO week for a score or action reminder and the ISO month
 * for a critical coverage gap; see ReminderDeliveries::periodKeyFor().
 */
final class SkillReminderDelivery extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    /** Stored in development_action_id for a score reminder; see the migration. */
    public const NO_ACTION = 0;

    /** Stored in employee_entity_id for a coverage gap, which has no subject employee. */
    public const NO_EMPLOYEE = 0;

    /** Stored in department_id for every rule but a coverage gap, and for a gap in no department. */
    public const NO_DEPARTMENT = 0;

    protected $table = 'people_connector_skill_reminder_deliveries';

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
            'rule' => ReminderRule::class,
            'state' => ReminderDeliveryState::class,
            'development_action_id' => 'integer',
            'department_id' => 'integer',
            'due_on' => 'immutable_date',
            'attempted_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }

    public function developmentActionId(): ?int
    {
        $id = (int) $this->development_action_id;

        return $id === self::NO_ACTION ? null : $id;
    }

    public function departmentId(): ?int
    {
        $id = (int) $this->department_id;

        return $id === self::NO_DEPARTMENT ? null : $id;
    }
}
