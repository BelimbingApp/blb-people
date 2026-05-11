<?php

namespace App\Modules\People\Payroll\Data;

class ProfileSchema
{
    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $countryIso,
        public readonly string $profileType,
        public readonly string $sourcePack,
        public readonly string $sourceVersion,
        public readonly array $fields,
        public readonly array $metadata = [],
    ) {}
}
