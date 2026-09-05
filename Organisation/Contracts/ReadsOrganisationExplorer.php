<?php

namespace App\Domains\People\Organisation\Contracts;

use App\Base\Authz\DTO\Actor;
use App\Domains\People\Organisation\Data\OrganisationAggregate;
use App\Domains\People\Organisation\Data\OrganisationDrillThrough;
use App\Domains\People\Organisation\Data\OrganisationNode;
use App\Domains\People\Organisation\Enums\OrganisationIndicator;
use App\Domains\People\Organisation\Enums\OrganisationPurpose;
use App\Domains\People\Organisation\Enums\OrganisationReadRefusal;
use App\Domains\People\Provider\Data\WorkforceSubject;
use DateTimeInterface;

interface ReadsOrganisationExplorer
{
    public function structureNode(
        Actor $actor,
        WorkforceSubject $subject,
        DateTimeInterface $asOf,
    ): OrganisationNode|OrganisationReadRefusal;

    public function aggregateIndicator(
        Actor $actor,
        WorkforceSubject $scope,
        OrganisationIndicator $indicator,
        DateTimeInterface $asOf,
    ): OrganisationAggregate|OrganisationReadRefusal;

    public function drillThrough(
        Actor $actor,
        OrganisationNode $node,
        OrganisationPurpose $purpose,
    ): OrganisationDrillThrough|OrganisationReadRefusal;
}
