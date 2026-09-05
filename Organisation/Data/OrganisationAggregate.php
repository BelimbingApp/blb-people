<?php

namespace App\Domains\People\Organisation\Data;

use App\Domains\People\Organisation\Enums\OrganisationIndicator;
use App\Domains\People\Provider\Data\WorkforceSubject;
use DateTimeImmutable;

final readonly class OrganisationAggregate
{
    public function __construct(
        public WorkforceSubject $scope,
        public OrganisationIndicator $indicator,
        public ?int $value,
        public DateTimeImmutable $asOf,
        public bool $incomplete = false,
        public bool $suppressed = false,
    ) {}
}
