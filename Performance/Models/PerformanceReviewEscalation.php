<?php

namespace App\Domains\People\Performance\Models;

use App\Domains\People\Performance\Enums\EscalationAudience;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;

/** One review that outlasted two weekly reminders, and who was told. */
final class PerformanceReviewEscalation extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_performance_review_escalations';

    protected function casts(): array
    {
        return [
            'audience' => EscalationAudience::class,
            'notified_at' => 'immutable_datetime',
        ];
    }
}
