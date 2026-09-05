<?php

namespace App\Domains\People\Performance\Models;

use App\Domains\People\Performance\Enums\JobDescriptionStatus;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;

/** One immutable published version of a company-owned job description. */
class JobDescription extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_job_descriptions';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => JobDescriptionStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'responsibilities' => 'array',
            'requirement_profile_version' => 'integer',
            'published_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }
}
