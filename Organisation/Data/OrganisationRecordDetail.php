<?php

namespace App\Domains\People\Organisation\Data;

use App\Domains\People\Provider\Data\WorkforceSubject;
use DateTimeImmutable;

final readonly class OrganisationRecordDetail
{
    public function __construct(
        public WorkforceSubject $subject,
        public DateTimeImmutable $asOf,
        public OrganisationDetailSection $jobDescription,
        public OrganisationDetailSection $performance,
    ) {}
}
