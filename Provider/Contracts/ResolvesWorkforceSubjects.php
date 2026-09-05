<?php

namespace App\Domains\People\Provider\Contracts;

use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Data\WorkforceSubjectResolution;

/**
 * Provider-neutral replacement seam for the connector-owned WorkforceEntity,
 * WorkforceEmployeeProjection, WorkforceOrganizationUnitProjection,
 * WorkforcePositionProjection, WorkforceReference and
 * ReferencesWorkforceEntities shapes used by Skill and Training.
 */
interface ResolvesWorkforceSubjects
{
    public function resolve(WorkforceSubject $subject): WorkforceSubjectResolution;
}
