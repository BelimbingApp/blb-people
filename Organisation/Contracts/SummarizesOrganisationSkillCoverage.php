<?php

namespace App\Domains\People\Organisation\Contracts;

use App\Domains\People\Organisation\Data\OrganisationIndicatorValue;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use DateTimeInterface;

interface SummarizesOrganisationSkillCoverage
{
    /** @param list<WorkforceEmployee> $employees */
    public function summarize(
        string $companyStableId,
        array $employees,
        DateTimeInterface $asOf,
    ): OrganisationIndicatorValue;
}
