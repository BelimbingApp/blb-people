<?php

namespace App\Domains\People\Performance\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;

/**
 * Attributable workplace evidence. A correction never rewrites this row; it
 * writes a superseding one and marks this as corrected, so a review that
 * pinned it still reads what it was shown.
 */
final class PerformanceObservation extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_performance_observations';

    protected function casts(): array
    {
        return [
            'window_start' => 'immutable_date',
            'window_end' => 'immutable_date',
            'recorded_at' => 'immutable_datetime',
            'source_version' => 'integer',
            'corrected_at' => 'immutable_datetime',
        ];
    }
}
