<?php

namespace App\Domains\People\Skills\Tests\Support;

use App\Core\Company\Models\Company;

final readonly class TwoCompanyTenant
{
    public function __construct(
        public int $tenantId,
        public Company $alphaCompany,
        public int $alphaCompanyEntityId,
        public Company $betaCompany,
        public int $betaCompanyEntityId,
    ) {}
}
