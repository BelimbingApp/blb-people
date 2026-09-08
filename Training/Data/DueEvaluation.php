<?php

namespace App\Domains\People\Training\Data;

use Carbon\CarbonImmutable;

/** One attended participant whose evaluation is due soon or overdue. */
final readonly class DueEvaluation
{
    public function __construct(
        public int $participantId,
        public int $eventId,
        /** Null when the participant has not opened a form; the due date then comes from the event clock. */
        public ?int $evaluationId,
        public int $employeeEntityId,
        public string $eventTitle,
        public CarbonImmutable $dueOn,
        /** Negative while still due: -3 is "due in three days". */
        public int $daysOverdue,
    ) {}

    public function overdue(): bool
    {
        return $this->daysOverdue > 0;
    }
}
