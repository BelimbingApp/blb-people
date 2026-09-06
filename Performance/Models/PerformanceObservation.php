<?php

namespace App\Domains\People\Performance\Models;

use App\Domains\People\Performance\Exceptions\PerformanceReviewException;
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

    protected static function booted(): void
    {
        self::updating(function (PerformanceObservation $observation): void {
            // The store corrects by writing a superseding row and stamping this
            // one as corrected. That stamp is the only edit an observation
            // takes; the evidence somebody attributed stays as recorded.
            if (array_diff(array_keys($observation->getDirty()), ['corrected_at', 'updated_at']) !== []) {
                throw new PerformanceReviewException(
                    'A recorded observation is not edited; record a superseding correction instead.',
                );
            }
        });

        self::deleting(function (): void {
            throw new PerformanceReviewException('A recorded observation is not deleted.');
        });
    }

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
