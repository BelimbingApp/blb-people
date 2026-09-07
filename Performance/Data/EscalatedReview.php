<?php

namespace App\Domains\People\Performance\Data;

use App\Domains\People\Performance\Enums\EscalationAudience;

/** One review being handed past the manager who did not act on it. */
final readonly class EscalatedReview
{
    public function __construct(
        public int $reviewId,
        public int $managerUserId,
        /** Null when the reporting line ends at this manager. */
        public ?int $escalatedToUserId,
        public EscalationAudience $audience,
    ) {}
}
