<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Provider\Contracts\ResolvesWorkforceSubjects;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Provider\Enums\WorkforceSubjectRefusal;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;

test('a subject that names another provider does not resolve as a native record', function (): void {
    [$tenant, $company] = createTenantWithCompany();
    app(TenantContext::class)->set($tenant->id);

    $resolution = app(ResolvesWorkforceSubjects::class)->resolve(new WorkforceSubject(
        tenantId: $tenant->id,
        companyId: $company->id,
        type: WorkforceResourceType::Company,
        stableId: (string) $company->id,
        externalReference: new ExternalReference(
            WorkforceResourceType::Company,
            'hr2000-company-1',
            'hr2000.sbg',
        ),
    ));

    expect($resolution->record)->toBeNull()
        ->and($resolution->refusal)->toBe(WorkforceSubjectRefusal::Unknown);
});

test('a subject that names the native provider still resolves', function (): void {
    [$tenant, $company] = createTenantWithCompany();
    app(TenantContext::class)->set($tenant->id);

    $resolution = app(ResolvesWorkforceSubjects::class)->resolve(new WorkforceSubject(
        tenantId: $tenant->id,
        companyId: $company->id,
        type: WorkforceResourceType::Company,
        stableId: (string) $company->id,
        externalReference: new ExternalReference(
            WorkforceResourceType::Company,
            (string) $company->id,
        ),
    ));

    expect($resolution->refusal)->toBeNull()
        ->and($resolution->record?->getKey())->toBe($company->getKey());
});

test('the resolver refuses every subject when no tenant context is established', function (): void {
    [$tenant, $company] = createTenantWithCompany();
    app(TenantContext::class)->clear();

    $resolution = app(ResolvesWorkforceSubjects::class)->resolve(new WorkforceSubject(
        tenantId: $tenant->id,
        companyId: $company->id,
        type: WorkforceResourceType::Company,
        stableId: (string) $company->id,
    ));

    expect($resolution->record)->toBeNull()
        ->and($resolution->refusal)->toBe(WorkforceSubjectRefusal::Unknown);
});

test('a tenant-wide reference entry that belongs to no company is refused rather than resolved', function (): void {
    [$tenant, $company] = createTenantWithCompany();
    $entry = PeopleReferenceEntry::query()->create([
        'company_id' => null,
        'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'TENANT-WIDE',
        'name' => 'Tenant Wide',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    app(TenantContext::class)->set($tenant->id);

    $resolution = app(ResolvesWorkforceSubjects::class)->resolve(new WorkforceSubject(
        tenantId: $tenant->id,
        companyId: $company->id,
        type: WorkforceResourceType::OrganizationUnit,
        stableId: (string) $entry->getKey(),
    ));

    expect($resolution->refusal)->toBe(WorkforceSubjectRefusal::Unknown);
});

test('a workforce subject refuses an empty stable identifier', function (): void {
    expect(fn () => new WorkforceSubject(1, 1, WorkforceResourceType::Company, '  '))
        ->toThrow(InvalidArgumentException::class, 'stable ID cannot be empty');
});

test('a workforce subject refuses an external reference for a different resource type', function (): void {
    expect(fn () => new WorkforceSubject(
        tenantId: 1,
        companyId: 1,
        type: WorkforceResourceType::Company,
        stableId: '1',
        externalReference: new ExternalReference(WorkforceResourceType::Employee, 'e-1'),
    ))->toThrow(InvalidArgumentException::class, 'must have the subject type');
});

test('an external reference refuses an empty provider identity', function (): void {
    expect(fn () => new ExternalReference(WorkforceResourceType::Company, 'c-1', '  '))
        ->toThrow(InvalidArgumentException::class, 'provider and ID cannot be empty');
});

test('an external reference publishes the provider identity it was given', function (): void {
    $native = new ExternalReference(WorkforceResourceType::Company, 'c-1');
    $foreign = new ExternalReference(WorkforceResourceType::Company, 'c-1', 'hr2000.sbg');

    expect($native->providerId)->toBe('blb-people')
        ->and($native->jsonSerialize()['provider_id'])->toBe('blb-people')
        ->and($foreign->providerId)->toBe('hr2000.sbg')
        ->and($foreign->jsonSerialize()['provider_id'])->toBe('hr2000.sbg');
});
