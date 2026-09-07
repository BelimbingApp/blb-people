<?php

namespace App\Domains\People\Training\Services;

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Core\User\Models\User;

/**
 * Users granted a capability in one company through an explicit principal
 * role. A grant-all policy is not an inbox: whoever holds everything by policy
 * is not the person a Training message is addressed to.
 */
final class TrainingCapabilityHolders
{
    /** @return list<User> */
    public function users(int $companyEntityId, string $capability): array
    {
        $userIds = PrincipalRole::query()
            ->join('base_authz_roles', 'base_authz_roles.id', '=', 'base_authz_principal_roles.role_id')
            ->join('base_authz_role_capabilities', 'base_authz_role_capabilities.role_id', '=', 'base_authz_roles.id')
            ->where('base_authz_principal_roles.principal_type', PrincipalType::USER->value)
            ->where('base_authz_principal_roles.company_id', $companyEntityId)
            ->where('base_authz_role_capabilities.capability_key', $capability)
            ->distinct()
            ->pluck('base_authz_principal_roles.principal_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($userIds === []) {
            return [];
        }

        return User::query()->whereIn('id', $userIds)->orderBy('id')->get()->all();
    }
}
