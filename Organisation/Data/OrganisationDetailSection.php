<?php

namespace App\Domains\People\Organisation\Data;

use App\Domains\People\Organisation\Enums\OrganisationReadRefusal;

final readonly class OrganisationDetailSection
{
    /** @param list<array<string, mixed>> $records */
    public function __construct(
        public array $records,
        public ?OrganisationReadRefusal $refusal = null,
    ) {
        if ($records !== [] && $refusal !== null) {
            throw new \InvalidArgumentException('A restricted organisation detail section cannot carry records.');
        }
    }

    /** @param list<array<string, mixed>> $records */
    public static function available(array $records): self
    {
        return new self($records);
    }

    public static function restricted(OrganisationReadRefusal $refusal): self
    {
        return new self([], $refusal);
    }
}
