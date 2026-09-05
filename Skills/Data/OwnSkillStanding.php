<?php

namespace App\Domains\People\Skills\Data;

use App\Domains\People\Provider\Data\WorkforceSubject;
use DateTimeImmutable;

final readonly class OwnSkillStanding
{
    public DateTimeImmutable $asOf;

    public DateTimeImmutable $cutoff;

    /**
     * @param  list<OwnAssessmentOutcome>  $standing  Canonical score projection's source assessments.
     * @param  list<OwnAssessmentOutcome>  $outcomes  Own finalized history.
     */
    public function __construct(
        public WorkforceSubject $subject,
        public DateTimeImmutable $generatedAt,
        public DateTimeImmutable $workforceObservedAt,
        public array $standing,
        public array $outcomes,
    ) {
        $this->asOf = $generatedAt;
        $this->cutoff = $generatedAt;
    }
}
