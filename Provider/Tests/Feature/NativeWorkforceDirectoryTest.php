<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;

test('native company mapping is explicit and fails closed outside the current tenant', function (): void {
    [$tenant, $company] = createTenantWithCompany();
    [, $otherCompany] = createTenantWithCompany();
    $inactive = Company::factory()->create(['tenant_id' => $tenant->id, 'status' => 'suspended']);
    $directory = app(ReadsWorkforceDirectory::class);

    app(TenantContext::class)->set($tenant->id);

    expect($directory->companyForPlatform($company->id)?->reference->externalId)->toBe((string) $company->id)
        ->and($directory->company((string) $company->id)?->reference->externalId)->toBe((string) $company->id)
        ->and($directory->companyForPlatform($otherCompany->id))->toBeNull()
        ->and($directory->company((string) $inactive->id))->toBeNull();

    app(TenantContext::class)->clear();
    expect($directory->company((string) $company->id))->toBeNull();
});

test('employee enumeration stays in one company and publishes only explicit active relationships', function (): void {
    [$tenant, $company] = createTenantWithCompany();
    $otherCompany = Company::factory()->create(['tenant_id' => $tenant->id]);
    $type = DepartmentType::query()->create([
        'code' => 'directory-ops',
        'name' => 'Directory Operations',
        'category' => 'operational',
        'is_active' => true,
    ]);
    $department = Department::query()->create([
        'company_id' => $company->id,
        'department_type_id' => $type->id,
        'status' => 'active',
    ]);
    $manager = Employee::factory()->create(['company_id' => $company->id, 'status' => 'active']);
    $department->update(['head_id' => $manager->id]);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'supervisor_id' => $manager->id,
        'designation' => 'Directory Engineer',
        'status' => 'active',
    ]);
    $organization = directoryReference($company, PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT, 'OPS', 'Directory Operations');
    $position = directoryReference($company, PeopleReferenceEntry::TYPE_JOB_TITLE, 'ENG', 'Directory Engineer');
    EmployeeWorkProfile::query()->create([
        'employee_id' => $employee->id,
        'organization_unit_id' => $organization->id,
        'job_title_id' => $position->id,
    ]);
    Employee::factory()->create(['company_id' => $otherCompany->id, 'status' => 'active']);
    Employee::factory()->create(['company_id' => $company->id, 'status' => 'inactive']);
    Employee::factory()->create(['company_id' => $company->id, 'status' => 'active', 'employee_type' => 'agent']);
    app(TenantContext::class)->set($tenant->id);

    $employees = collect(app(ReadsWorkforceDirectory::class)->employees((string) $company->id));
    $record = $employees->first(fn ($candidate) => $candidate->reference->externalId === (string) $employee->id);

    expect($employees)->toHaveCount(2)
        ->and($record?->organizationReference?->externalId)->toBe((string) $organization->id)
        ->and($record?->positionReference?->externalId)->toBe((string) $position->id)
        ->and($record?->managerReference?->externalId)->toBe((string) $manager->id)
        ->and($record?->departmentHeadReference?->externalId)->toBe((string) $manager->id)
        ->and(app(ReadsWorkforceDirectory::class)->employees((string) $otherCompany->id))->toHaveCount(1);

    $organization->update(['company_id' => $otherCompany->id]);
    $position->update(['status' => PeopleReferenceEntry::STATUS_INACTIVE]);
    $invalidRelationship = collect(app(ReadsWorkforceDirectory::class)->employees((string) $company->id))
        ->first(fn ($candidate) => $candidate->reference->externalId === (string) $employee->id);
    expect($invalidRelationship?->organizationReference)->toBeNull()
        ->and($invalidRelationship?->positionReference)->toBeNull();

    $manager->update(['company_id' => $otherCompany->id]);
    $invalidReporting = collect(app(ReadsWorkforceDirectory::class)->employees((string) $company->id))
        ->first(fn ($candidate) => $candidate->reference->externalId === (string) $employee->id);
    expect($invalidReporting?->managerReference)->toBeNull()
        ->and($invalidReporting?->departmentHeadReference)->toBeNull();
});

test('actor binding requires the active reviewed portal relationship in the same company', function (): void {
    [$tenant, $company] = createTenantWithCompany();
    $employee = Employee::factory()->create(['company_id' => $company->id, 'status' => 'active']);
    $user = User::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);
    app(TenantContext::class)->set($tenant->id);
    $directory = app(ReadsWorkforceDirectory::class);

    expect($directory->employeeForUser((string) $company->id, $user->id))->toBeNull();

    $access = EmployeePortalAccess::query()->create([
        'employee_id' => $employee->id,
        'user_id' => $user->id,
        'display_name' => $employee->displayName(),
        'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);

    expect($directory->employeeForUser((string) $company->id, $user->id)?->reference->externalId)
        ->toBe((string) $employee->id);

    $unboundUser = User::factory()->create(['company_id' => $company->id]);
    $access->update(['user_id' => $unboundUser->id]);
    expect($directory->employeeForUser((string) $company->id, $unboundUser->id))->toBeNull();
    $access->update(['user_id' => $user->id]);

    $access->update(['status' => EmployeePortalAccess::STATUS_REVOKED]);
    expect($directory->employeeForUser((string) $company->id, $user->id))->toBeNull();
    $access->update(['status' => EmployeePortalAccess::STATUS_ACTIVE]);

    $otherCompany = Company::factory()->create(['tenant_id' => $tenant->id]);
    $user->update(['company_id' => $otherCompany->id]);
    expect($directory->employeeForUser((string) $company->id, $user->id))->toBeNull();
    $enumerated = collect($directory->employees((string) $company->id))
        ->first(fn ($candidate) => $candidate->reference->externalId === (string) $employee->id);
    expect($enumerated?->userReference)->toBeNull();
    $user->update(['company_id' => $company->id]);

    $employee->update(['status' => 'inactive']);
    expect($directory->employeeForUser((string) $company->id, $user->id))->toBeNull();

    $otherEmployee = Employee::factory()->create(['company_id' => $otherCompany->id, 'status' => 'active']);
    $otherUser = User::factory()->create(['company_id' => $otherCompany->id, 'employee_id' => $otherEmployee->id]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $otherEmployee->id,
        'user_id' => $otherUser->id,
        'display_name' => $otherEmployee->displayName(),
        'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    expect($directory->employeeForUser((string) $company->id, $otherUser->id))->toBeNull();
});

test('native identities never invent a company remap without an auditable provider fact', function (): void {
    [$tenant, $company] = createTenantWithCompany();
    app(TenantContext::class)->set($tenant->id);

    expect(app(ReadsWorkforceDirectory::class)->remap(
        WorkforceResourceType::Company,
        'legacy-company-id',
        (string) $company->id,
    ))->toBeNull();
});

test('company stable ids with a numeric prefix are refused rather than cast', function (): void {
    [$tenant, $company] = createTenantWithCompany();
    app(TenantContext::class)->set($tenant->id);

    // (int) '12-not-mine' is 12. Without ctype_digit the directory answers for
    // a company the caller did not name.
    expect(app(ReadsWorkforceDirectory::class)->company($company->id.'-not-mine'))->toBeNull();
});

test('enumerated employees publish a user reference only for an active portal binding that matches the employee user', function (): void {
    [$tenant, $company] = createTenantWithCompany();
    $employee = Employee::factory()->create(['company_id' => $company->id, 'status' => 'active']);
    $user = User::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);
    app(TenantContext::class)->set($tenant->id);
    $directory = app(ReadsWorkforceDirectory::class);

    expect(collect($directory->employees((string) $company->id))
        ->first(fn ($candidate) => $candidate->reference->externalId === (string) $employee->id)
        ?->userReference)->toBeNull();

    $access = EmployeePortalAccess::query()->create([
        'employee_id' => $employee->id,
        'user_id' => $user->id,
        'display_name' => $employee->displayName(),
        'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);

    expect(collect($directory->employees((string) $company->id))
        ->first(fn ($candidate) => $candidate->reference->externalId === (string) $employee->id)
        ?->userReference?->externalId)->toBe((string) $user->id);

    $access->update(['status' => EmployeePortalAccess::STATUS_REVOKED]);
    expect(collect($directory->employees((string) $company->id))
        ->first(fn ($candidate) => $candidate->reference->externalId === (string) $employee->id)
        ?->userReference)->toBeNull();
    $access->update(['status' => EmployeePortalAccess::STATUS_ACTIVE]);

    $stranger = User::factory()->create(['company_id' => $company->id]);
    $access->update(['user_id' => $stranger->id]);
    expect(collect($directory->employees((string) $company->id))
        ->first(fn ($candidate) => $candidate->reference->externalId === (string) $employee->id)
        ?->userReference)->toBeNull();
});

test('a relationship entry of the wrong reference type is not published', function (): void {
    [$tenant, $company] = createTenantWithCompany();
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);
    $organization = directoryReference($company, PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT, 'OPS', 'Ops');
    $position = directoryReference($company, PeopleReferenceEntry::TYPE_JOB_TITLE, 'ENG', 'Engineer');
    EmployeeWorkProfile::query()->create([
        'employee_id' => $employee->id,
        'organization_unit_id' => $organization->id,
        'job_title_id' => $position->id,
    ]);
    // Bad data: the organization_unit_id column points at a job-title row.
    $organization->update(['type' => PeopleReferenceEntry::TYPE_JOB_TITLE]);
    app(TenantContext::class)->set($tenant->id);

    $record = collect(app(ReadsWorkforceDirectory::class)->employees((string) $company->id))
        ->first(fn ($candidate) => $candidate->reference->externalId === (string) $employee->id);

    expect($record?->organizationReference)->toBeNull()
        ->and($record?->positionReference?->externalId)->toBe((string) $position->id);
});

function directoryReference(Company $company, string $type, string $code, string $name): PeopleReferenceEntry
{
    return PeopleReferenceEntry::query()->create([
        'company_id' => $company->id,
        'type' => $type,
        'code' => $code,
        'name' => $name,
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
}

test('a tenant-scoped portal user still binds to the employee that holds it', function (): void {
    [$tenant, $company] = createTenantWithCompany();
    $employee = Employee::factory()->create(['company_id' => $company->id, 'status' => 'active']);
    // A user with no company belongs to the tenant rather than to one company.
    // Both company checks in this service admit that case on purpose; without
    // the null arm such a user silently loses its identity binding.
    $user = User::factory()->create(['company_id' => null, 'employee_id' => $employee->id]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $employee->id,
        'user_id' => $user->id,
        'display_name' => $employee->displayName(),
        'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    app(TenantContext::class)->set($tenant->id);
    $directory = app(ReadsWorkforceDirectory::class);

    expect($directory->employees((string) $company->id)[0]->userReference?->externalId)->toBe((string) $user->id)
        ->and($directory->employeeForUser((string) $company->id, $user->id)?->reference->externalId)
        ->toBe((string) $employee->id);
});
