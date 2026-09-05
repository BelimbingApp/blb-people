<?php

namespace App\Domains\People\Skills\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;

/** Resolves the native People company an authenticated platform user may act for. */
final class CompanyAttribution
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ReadsWorkforceDirectory $directory,
    ) {}

    /** @return array<int, string> */
    public function allowedCompanyEntities(?User $actor): array
    {
        $this->tenantContext->requireTenantId();
        $companyId = $actor?->getCompanyId();

        if ($companyId === null) {
            return [];
        }

        $company = $this->directory->companyForPlatform($companyId);

        if ($company === null || ! $company->active || ! ctype_digit($company->reference->externalId)) {
            return [];
        }

        return [(int) $company->reference->externalId => $company->name];
    }

    public function mayActFor(?User $actor, int $companyId): bool
    {
        return array_key_exists($companyId, $this->allowedCompanyEntities($actor));
    }
}
