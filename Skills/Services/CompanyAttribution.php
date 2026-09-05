<?php

namespace App\Domains\People\Skills\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;

/** Resolves the native People company an authenticated platform user may act for. */
final class CompanyAttribution
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /** @return array<int, string> */
    public function allowedCompanyEntities(?User $actor): array
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $companyId = $actor?->getCompanyId();

        if ($companyId === null) {
            return [];
        }

        return Company::query()
            ->forTenant($tenantId)
            ->whereKey($companyId)
            ->where('status', 'active')
            ->pluck('name', 'id')
            ->all();
    }

    public function mayActFor(?User $actor, int $companyId): bool
    {
        return array_key_exists($companyId, $this->allowedCompanyEntities($actor));
    }
}
