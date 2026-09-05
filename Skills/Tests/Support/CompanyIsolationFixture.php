<?php

namespace App\Domains\People\Skills\Tests\Support;

use App\Base\Tenancy\Models\Tenant;
use App\Core\Company\Models\Company;

final class CompanyIsolationFixture
{
    public static function twoCompaniesInOneTenant(
        string $alphaName = 'Alpha Industries',
        string $betaName = 'Beta Works',
    ): TwoCompanyTenant {
        $tenant = Tenant::query()->create(['name' => 'Two Company Tenant', 'status' => 'active']);
        $alpha = Company::factory()->create(['tenant_id' => $tenant->id, 'name' => $alphaName, 'status' => 'active']);
        $beta = Company::factory()->create(['tenant_id' => $tenant->id, 'name' => $betaName, 'status' => 'active']);

        return new TwoCompanyTenant(
            tenantId: (int) $tenant->id,
            alphaCompany: $alpha,
            alphaCompanyEntityId: (int) $alpha->id,
            betaCompany: $beta,
            betaCompanyEntityId: (int) $beta->id,
        );
    }
}
