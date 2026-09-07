<?php

namespace App\Domains\People\Performance\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;

/**
 * The employee's own words about a released review. Kept against the review
 * version it answered, and never moved onto a correction — plan 0009 asks that
 * disputes survive "including outcomes that do not change the review".
 */
final class PerformanceReviewResponse extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_performance_review_responses';

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime'];
    }
}
