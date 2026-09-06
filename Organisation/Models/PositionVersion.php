<?php

namespace App\Domains\People\Organisation\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;

final class PositionVersion extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_position_versions';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
        ];
    }
}
