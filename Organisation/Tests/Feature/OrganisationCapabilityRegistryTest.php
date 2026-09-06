<?php

use App\Base\Authz\Capability\CapabilityCatalog;
use App\Base\Authz\Capability\CapabilityRegistry;

test('People contributes only valid organisation audience capabilities to the composed registry', function (): void {
    /** @var array<string, mixed> $authzConfig */
    $authzConfig = config('authz');
    $catalog = CapabilityCatalog::fromConfig($authzConfig);
    $registry = CapabilityRegistry::fromCatalog($catalog);
    $peopleRejected = array_filter(
        $catalog->rejected(),
        fn (string $capability): bool => str_starts_with($capability, 'people.'),
        ARRAY_FILTER_USE_KEY,
    );

    expect($peopleRejected)->toBe([])
        ->and($registry->forDomain('people'))->toContain(
            'people.organisation.audience.executive.view',
            'people.organisation.audience.hod.view',
            'people.organisation.audience.employee.view',
            'people.organisation.audience.hr.view',
            'people.organisation.audience.auditor.view',
        );
});
