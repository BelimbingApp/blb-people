<?php

namespace App\Domains\People\Organisation\Data;

use App\Domains\People\Provider\Data\WorkforceSubject;
use DateTimeImmutable;

final readonly class OrganisationNode
{
    public function __construct(
        public WorkforceSubject $subject,
        public string $label,
        public bool $active,
        public DateTimeImmutable $asOf,
        public DateTimeImmutable $observedAt,
    ) {
        if (trim($label) === '') {
            throw new \InvalidArgumentException('An organisation node label cannot be empty.');
        }
    }
}
