<?php

namespace App\Domains\People\Performance\Models;

use App\Domains\People\Performance\Enums\PerformanceOutcome;
use App\Domains\People\Performance\Enums\PerformanceReviewStatus;
use App\Domains\People\Performance\Exceptions\PerformanceReviewException;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;

/** One immutable released version of a period performance review. */
final class PerformanceReview extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_performance_reviews';

    protected static function booted(): void
    {
        self::updating(function (PerformanceReview $review): void {
            $original = PerformanceReviewStatus::from((string) $review->getRawOriginal('status'));

            if ($original === PerformanceReviewStatus::Draft) {
                return;
            }

            // The one edit a released review accepts is being superseded by its
            // own correction. Everything it said stays exactly as released.
            $dirty = array_keys($review->getDirty());
            if ($original === PerformanceReviewStatus::Finalized
                && $review->status === PerformanceReviewStatus::Superseded
                && array_diff($dirty, ['status', 'superseded_at', 'updated_at']) === []) {
                return;
            }

            throw new PerformanceReviewException(
                'A finalized performance review cannot be modified; a correction is a new version.',
            );
        });

        self::deleting(function (PerformanceReview $review): void {
            if ($review->status !== PerformanceReviewStatus::Draft) {
                throw new PerformanceReviewException('A finalized performance review cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => PerformanceReviewStatus::class,
            'outcome' => PerformanceOutcome::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'cutoff_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }
}
