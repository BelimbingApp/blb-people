<?php

namespace App\Domains\People\Organisation\Contracts;

use App\Base\Authz\DTO\Actor;
use App\Domains\People\Organisation\Data\OrganisationRecordDetail;
use App\Domains\People\Provider\Data\WorkforceSubject;
use DateTimeInterface;

interface ContributesOrganisationRecordDetail
{
    public function detail(
        Actor $actor,
        WorkforceSubject $subject,
        DateTimeInterface $asOf,
    ): OrganisationRecordDetail;
}
