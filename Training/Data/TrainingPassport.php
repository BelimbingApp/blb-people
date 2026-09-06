<?php

namespace App\Domains\People\Training\Data;

use App\Domains\People\Provider\Data\WorkforceSubject;
use DateTimeInterface;

final readonly class TrainingPassport
{
    /**
     * @param  list<TrainingPassportEvent>  $events
     * @param  list<TrainingPassportCertificate>  $certificates
     * @param  list<TrainingPassportSkill>  $skills
     */
    public function __construct(
        public WorkforceSubject $subject,
        public DateTimeInterface $generatedAt,
        public array $events,
        public array $certificates,
        public array $skills,
    ) {}
}
