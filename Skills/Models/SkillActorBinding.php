<?php

namespace App\Domains\People\Skills\Models;

use App\Domains\People\Skills\Contracts\ReferencesWorkforceEntities;
use App\Domains\People\Skills\Data\WorkforceReference;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;

final class SkillActorBinding extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    protected $table = 'people_connector_skill_actor_bindings';

    /** @return list<WorkforceReference> */
    public function workforceReferences(): array
    {
        return [
            new WorkforceReference('employee_entity_id', WorkforceResourceType::Employee),
            new WorkforceReference('user_entity_id', WorkforceResourceType::User),
        ];
    }

    protected function casts(): array
    {
        return [
            'platform_user_id' => 'integer',
            'employee_entity_id' => 'integer',
            'user_entity_id' => 'integer',
            'confirmed_by_user_id' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'revoked_by_user_id' => 'integer',
        ];
    }

    /** @return array{name: string, id: int} */
    public function getAuditSubject(): array
    {
        return ['name' => 'skill_actor_binding', 'id' => (int) $this->getKey()];
    }
}
