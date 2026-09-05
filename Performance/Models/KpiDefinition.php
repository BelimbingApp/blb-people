<?php

namespace App\Domains\People\Performance\Models;

use App\Domains\People\Performance\Enums\KpiDirection;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;

class KpiDefinition extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_kpi_definitions';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'direction' => KpiDirection::class,
            'precision' => 'integer',
        ];
    }
}
