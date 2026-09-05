<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Provider\Contracts\ResolvesWorkforceSubjects;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Provider\Enums\WorkforceSubjectRefusal;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;

test('native workforce subjects resolve each supported active record inside its tenant and company', function (): void {
    [$tenant, $company] = createTenantWithCompany();
    $employee = Employee::factory()->create(['company_id' => $company->id, 'status' => 'active']);
    $unit = workforceReference($company, PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT, 'OPS');
    $position = workforceReference($company, PeopleReferenceEntry::TYPE_JOB_TITLE, 'ENG');
    app(TenantContext::class)->set($tenant->id);
    $resolver = app(ResolvesWorkforceSubjects::class);

    $subjects = [
        WorkforceResourceType::Company->value => [$company, $company],
        WorkforceResourceType::Employee->value => [$employee, $company],
        WorkforceResourceType::OrganizationUnit->value => [$unit, $company],
        WorkforceResourceType::Position->value => [$position, $company],
    ];

    foreach ($subjects as $type => [$record, $owningCompany]) {
        $resolution = $resolver->resolve(new WorkforceSubject(
            tenantId: $tenant->id,
            companyId: $owningCompany->id,
            type: WorkforceResourceType::from($type),
            stableId: (string) $record->getKey(),
        ));

        expect($resolution->record)->toBeInstanceOf($record::class)
            ->and($resolution->record?->getKey())->toBe($record->getKey())
            ->and($resolution->refusal)->toBeNull();
    }
});

test('every native workforce subject type refuses a record owned by another company', function (): void {
    [$tenant, $company] = createTenantWithCompany();
    $otherCompany = Company::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create(['company_id' => $otherCompany->id, 'status' => 'active']);
    $unit = workforceReference($otherCompany, PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT, 'OTHER-OPS');
    $position = workforceReference($otherCompany, PeopleReferenceEntry::TYPE_JOB_TITLE, 'OTHER-ENG');
    app(TenantContext::class)->set($tenant->id);
    $resolver = app(ResolvesWorkforceSubjects::class);

    foreach ([
        WorkforceResourceType::Company->value => $otherCompany,
        WorkforceResourceType::Employee->value => $employee,
        WorkforceResourceType::OrganizationUnit->value => $unit,
        WorkforceResourceType::Position->value => $position,
    ] as $type => $record) {
        $resolution = $resolver->resolve(new WorkforceSubject(
            tenantId: $tenant->id,
            companyId: $company->id,
            type: WorkforceResourceType::from($type),
            stableId: (string) $record->getKey(),
        ));

        expect($resolution->record)->toBeNull()
            ->and($resolution->refusal)->toBe(WorkforceSubjectRefusal::WrongCompany);
    }
});

test('native workforce subjects fail closed for missing scope, another tenant, unknown and deactivated records', function (): void {
    [$tenant, $company] = createTenantWithCompany();
    [, $otherCompany] = createTenantWithCompany();
    $otherTenantEmployee = Employee::factory()->create(['company_id' => $otherCompany->id, 'status' => 'active']);
    $otherTenantUnit = workforceReference($otherCompany, PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT, 'REMOTE-OPS');
    $otherTenantPosition = workforceReference($otherCompany, PeopleReferenceEntry::TYPE_JOB_TITLE, 'REMOTE-ENG');
    $inactiveCompany = Company::factory()->create(['tenant_id' => $tenant->id, 'status' => 'suspended']);
    $inactiveEmployee = Employee::factory()->create(['company_id' => $company->id, 'status' => 'inactive']);
    $inactiveUnit = workforceReference($company, PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT, 'OLD-OPS', 'inactive');
    $inactivePosition = workforceReference($company, PeopleReferenceEntry::TYPE_JOB_TITLE, 'OLD-ENG', 'inactive');
    app(TenantContext::class)->set($tenant->id);
    $resolver = app(ResolvesWorkforceSubjects::class);

    $missingTenant = $resolver->resolve(new WorkforceSubject(null, $company->id, WorkforceResourceType::Company, (string) $company->id));
    $missingCompany = $resolver->resolve(new WorkforceSubject($tenant->id, null, WorkforceResourceType::Company, (string) $company->id));
    $unknown = $resolver->resolve(new WorkforceSubject($tenant->id, $company->id, WorkforceResourceType::Employee, 'not-a-native-id'));

    expect($missingTenant->refusal)->toBe(WorkforceSubjectRefusal::Unknown)
        ->and($missingCompany->refusal)->toBe(WorkforceSubjectRefusal::Unknown)
        ->and($unknown->refusal)->toBe(WorkforceSubjectRefusal::Unknown);

    foreach ([
        WorkforceResourceType::Company->value => $otherCompany,
        WorkforceResourceType::Employee->value => $otherTenantEmployee,
        WorkforceResourceType::OrganizationUnit->value => $otherTenantUnit,
        WorkforceResourceType::Position->value => $otherTenantPosition,
    ] as $type => $record) {
        $resolution = $resolver->resolve(new WorkforceSubject(
            $tenant->id,
            $company->id,
            WorkforceResourceType::from($type),
            (string) $record->getKey(),
        ));

        expect($resolution->refusal)->toBe(WorkforceSubjectRefusal::Unknown);
    }

    foreach ([
        WorkforceResourceType::Company->value => [$inactiveCompany, $inactiveCompany],
        WorkforceResourceType::Employee->value => [$inactiveEmployee, $company],
        WorkforceResourceType::OrganizationUnit->value => [$inactiveUnit, $company],
        WorkforceResourceType::Position->value => [$inactivePosition, $company],
    ] as $type => [$record, $owningCompany]) {
        $resolution = $resolver->resolve(new WorkforceSubject(
            $tenant->id,
            $owningCompany->id,
            WorkforceResourceType::from($type),
            (string) $record->getKey(),
        ));

        expect($resolution->refusal)->toBe(WorkforceSubjectRefusal::Deactivated);
    }
});

function workforceReference(Company $company, string $type, string $code, string $status = PeopleReferenceEntry::STATUS_ACTIVE): PeopleReferenceEntry
{
    return PeopleReferenceEntry::query()->create([
        'company_id' => $company->id,
        'type' => $type,
        'code' => $code,
        'name' => $code,
        'status' => $status,
    ]);
}
