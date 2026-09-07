<?php

namespace App\Domains\People\Performance\Data;

use App\Domains\People\Performance\Enums\OverdueReviewReason;
use Carbon\CarbonImmutable;

/** One review that has gone quiet, and who is being asked about it. */
final readonly class OverdueReview
{
    public function __construct(
        public int $reviewId,
        public int $managerUserId,
        public int $employeeEntityId,
        public OverdueReviewReason $reason,
        /** The moment the silence started: the draft's creation, or the release. */
        public CarbonImmutable $quietSince,
    ) {}
}
