<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Exceptions\TenantContextMissingException;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Contracts\ReadsWorkforceBootstrap;
use App\Domains\People\Provider\Data\WorkforceBootstrapRequest;
use App\Domains\People\Provider\Exceptions\InvalidWorkforceBootstrapCursorException;
use App\Domains\People\Settings\Models\EmployeePortalAccess;

test('workforce bootstrap fails closed without a tenant context', function (): void {
    app(TenantContext::class)->clear();

    expect(fn () => app(ReadsWorkforceBootstrap::class)->read(new WorkforceBootstrapRequest))
        ->toThrow(TenantContextMissingException::class);
});

test('workforce bootstrap projects only the current tenant and drops unsafe related identities', function (): void {
    [$tenant, $company] = createTenantWithCompany(
        ['name' => 'Provider Tenant'],
        ['name' => 'Provider Company', 'code' => 'PROVIDER'],
    );
    [$otherTenant, $otherCompany] = createTenantWithCompany(
        ['name' => 'Other Tenant'],
        ['name' => 'Other Company', 'code' => 'OTHER'],
    );
    $departmentType = DepartmentType::query()->create([
        'code' => 'provider-engineering',
        'name' => 'Engineering',
        'category' => 'operational',
        'is_active' => true,
    ]);
    $department = Department::query()->create([
        'company_id' => $company->id,
        'department_type_id' => $departmentType->id,
        'status' => 'active',
    ]);
    $manager = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'employee_number' => 'P-001',
        'full_name' => 'Provider Manager',
        'short_name' => null,
        'employee_type' => 'full_time',
    ]);
    $department->update(['head_id' => $manager->id]);
    $worker = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'supervisor_id' => $manager->id,
        'employee_number' => 'P-002',
        'full_name' => 'Provider Worker',
        'short_name' => 'Worker',
        'employee_type' => 'full_time',
        'email' => 'worker@provider.test',
    ]);
    $workerUser = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $worker->id,
    ]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $worker->id,
        'user_id' => $workerUser->id,
        'display_name' => $worker->displayName(),
        'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    $unconfirmedLinkWorker = Employee::factory()->create([
        'company_id' => $company->id,
        'employee_number' => 'P-005',
        'full_name' => 'Unconfirmed Link Worker',
        'employee_type' => 'full_time',
    ]);
    User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $unconfirmedLinkWorker->id,
    ]);
    $outsider = Employee::factory()->create([
        'company_id' => $otherCompany->id,
        'employee_number' => 'O-001',
        'full_name' => 'Other Tenant Employee',
        'employee_type' => 'full_time',
    ]);
    $unsafeCrossTenantRelationship = Employee::factory()->create([
        'company_id' => $company->id,
        'supervisor_id' => $outsider->id,
        'employee_number' => 'P-003',
        'full_name' => 'Unsafe Cross Tenant Relationship',
        'employee_type' => 'full_time',
    ]);
    $agent = Employee::factory()->create([
        'company_id' => $company->id,
        'employee_number' => 'P-AI',
        'full_name' => 'Non HR Agent',
        'employee_type' => 'agent',
    ]);
    $unsafeAgentRelationship = Employee::factory()->create([
        'company_id' => $company->id,
        'supervisor_id' => $agent->id,
        'employee_number' => 'P-004',
        'full_name' => 'Unsafe Agent Relationship',
        'employee_type' => 'full_time',
    ]);

    app(TenantContext::class)->set((int) $tenant->id);

    $page = app(ReadsWorkforceBootstrap::class)->read(new WorkforceBootstrapRequest);
    $payload = $page->jsonSerialize();
    $employees = collect($payload['employees'])->keyBy('employee_number');

    expect($payload['provider_id'])->toBe('blb-people')
        ->and($payload['supported_resources'])->toBe(['company', 'organization_unit', 'employee', 'user'])
        ->and($payload['positions'])->toBe([])
        ->and($payload['complete'])->toBeTrue()
        ->and(collect($payload['companies'])->pluck('reference.external_id')->all())
        ->toBe([(string) $company->id])
        ->and(collect($payload['organization_units'])->pluck('reference.external_id')->all())
        ->toBe([(string) $department->id])
        ->and($employees->keys()->sort()->values()->all())
        ->toBe(['P-001', 'P-002', 'P-003', 'P-004', 'P-005'])
        ->and($employees->has($outsider->employee_number))->toBeFalse()
        ->and($employees->has($agent->employee_number))->toBeFalse()
        ->and($employees->get('P-002')['reference']['external_id'])->toBe((string) $worker->id)
        ->and($employees->get('P-002')['user_reference']['external_id'])->toBe((string) $workerUser->id)
        ->and($employees->get('P-002')['organization_reference']['external_id'])->toBe((string) $department->id)
        ->and($employees->get('P-002')['manager_reference']['external_id'])->toBe((string) $manager->id)
        ->and($employees->get('P-002')['department_head_reference']['external_id'])->toBe((string) $manager->id)
        // Core's users.employee_id link exists but no active EmployeePortalAccess
        // confirms it — rule 8.3 requires the HR-governed confirmation, not just
        // the platform-admin-settable link, before a user_reference is projected.
        ->and($employees->get('P-005')['user_reference'])->toBeNull()
        ->and($employees->get('P-003')['reference']['external_id'])->toBe((string) $unsafeCrossTenantRelationship->id)
        ->and($employees->get('P-003')['manager_reference'])->toBeNull()
        ->and($employees->get('P-004')['reference']['external_id'])->toBe((string) $unsafeAgentRelationship->id)
        ->and($employees->get('P-004')['manager_reference'])->toBeNull()
        ->and($otherTenant->id)->not->toBe($tenant->id);
});

test('workforce bootstrap cursors preserve one boundary and cannot cross tenants', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Paging Tenant']);
    [$otherTenant] = createTenantWithCompany(['name' => 'Cursor Tenant']);
    $departmentType = DepartmentType::query()->create([
        'code' => 'provider-paging',
        'name' => 'Paging Organization',
        'category' => 'operational',
        'is_active' => true,
    ]);
    $department = Department::query()->create([
        'company_id' => $company->id,
        'department_type_id' => $departmentType->id,
        'status' => 'active',
    ]);

    $expectedIds = collect([
        Employee::factory()->create(['company_id' => $company->id, 'department_id' => $department->id, 'employee_type' => 'full_time']),
        Employee::factory()->create(['company_id' => $company->id, 'department_id' => $department->id, 'employee_type' => 'full_time']),
        Employee::factory()->create(['company_id' => $company->id, 'department_id' => $department->id, 'employee_type' => 'full_time']),
    ])->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();

    $context = app(TenantContext::class);
    $reader = app(ReadsWorkforceBootstrap::class);
    $context->set((int) $tenant->id);

    $first = $reader->read(new WorkforceBootstrapRequest(limit: 1));

    expect($first->complete)->toBeFalse()
        ->and($first->nextPageCursor)->not->toBeNull()
        ->and($first->resumeCursor)->toBeNull()
        ->and($first->companies)->toHaveCount(1)
        ->and($first->organizationUnits)->toHaveCount(1);

    $tamperOffset = intdiv(strlen($first->nextPageCursor), 2);
    $tamperedCharacter = $first->nextPageCursor[$tamperOffset] === 'A' ? 'B' : 'A';
    $tamperedCursor = substr_replace($first->nextPageCursor, $tamperedCharacter, $tamperOffset, 1);

    expect(fn () => $reader->read(new WorkforceBootstrapRequest($tamperedCursor, 1)))
        ->toThrow(InvalidWorkforceBootstrapCursorException::class, 'cursor is invalid');

    $lateEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'employee_type' => 'full_time',
    ]);

    $context->set((int) $otherTenant->id);

    expect(fn () => $reader->read(new WorkforceBootstrapRequest($first->nextPageCursor, 1)))
        ->toThrow(InvalidWorkforceBootstrapCursorException::class, 'does not belong to the current tenant');

    $context->set((int) $tenant->id);
    $second = $reader->read(new WorkforceBootstrapRequest($first->nextPageCursor, 1));
    $third = $reader->read(new WorkforceBootstrapRequest($second->nextPageCursor, 1));
    $projectedIds = collect([$first, $second, $third])
        ->flatMap(static fn ($page): array => array_map(
            static fn ($employee): string => $employee->reference->externalId,
            $page->employees,
        ))
        ->all();

    expect($second->asOf)->toEqual($first->asOf)
        ->and($third->asOf)->toEqual($first->asOf)
        ->and($second->companies)->toBe([])
        ->and($second->organizationUnits)->toBe([])
        ->and($third->companies)->toBe([])
        ->and($third->organizationUnits)->toBe([])
        ->and($third->complete)->toBeTrue()
        ->and($third->nextPageCursor)->toBeNull()
        ->and($third->resumeCursor)->not->toBeNull()
        ->and($projectedIds)->toBe($expectedIds)
        ->and($projectedIds)->not->toContain((string) $lateEmployee->id);
});

test('workforce bootstrap is not exposed over HTTP before service authentication exists', function (): void {
    $this->getJson('/api/people/provider/v1/workforce/bootstrap')->assertNotFound();
});
