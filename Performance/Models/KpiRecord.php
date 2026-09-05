<?php

namespace App\Domains\People\Performance\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;

class KpiRecord extends TenantOwnedModel
{
    use CompanyOwned;

    public const PROPOSED = 'proposed';

    public const REVIEWED = 'reviewed';

    public const PUBLISHED = 'published';

    protected $table = 'people_kpi_records';

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'evidence_references' => 'array',
            'confidential' => 'boolean',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
