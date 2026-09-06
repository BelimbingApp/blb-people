<?php

namespace App\Domains\People\Performance\Models;

use App\Domains\People\Performance\Enums\OverdueReviewReason;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;

/** One weekly nudge about one review, to one manager. */
final class PerformanceReviewReminder extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_performance_review_reminders';

    protected function casts(): array
    {
        return [
            'reason' => OverdueReviewReason::class,
            'notified_at' => 'immutable_datetime',
        ];
    }
}
