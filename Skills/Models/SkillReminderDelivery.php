<?php

namespace App\Domains\People\Skills\Models;

use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Contracts\ReferencesWorkforceEntities;
use App\Domains\People\Skills\Data\WorkforceReference;
use App\Domains\People\Skills\Enums\ReminderDeliveryState;
use App\Domains\People\Skills\Enums\ReminderRule;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;

/** One reminder, to one recipient, in one ISO week: sent, or failed and why. */
final class SkillReminderDelivery extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    /** Stored in development_action_id for a score reminder; see the migration. */
    public const NO_ACTION = 0;

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
}
