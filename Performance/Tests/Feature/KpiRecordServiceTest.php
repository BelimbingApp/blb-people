<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Performance\Exceptions\KpiRecordException;
use App\Domains\People\Performance\Models\KpiRecord;
use App\Domains\People\Performance\Services\KpiRecordService;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Skills\Exceptions\MissingCompanyScopeException;

afterEach(fn () => app(TenantContext::class)->clear());

test('performance capabilities are discovered from the module', function (): void {
    expect(config('authz.capabilities'))->toContain('people.performance.kpi.submit');
});

/** @return array{tenant: int, company: int, hod: User, hr: User, employee: User, owner: WorkforceSubject} */
function kpiFixture(): array
{
    [$tenant, $company] = createTenantWithCompany();
    app(TenantContext::class)->set($tenant->id);
    setupAuthzRoles();

    $type = DepartmentType::query()->create([
        'code' => 'kpi-department',
        'name' => 'KPI Department',
        'category' => 'operational',
        'is_active' => true,
    ]);
    $department = Department::query()->create([
        'company_id' => $company->id,
        'department_type_id' => $type->id,
        'status' => 'active',
    ]);
    $head = Employee::factory()->create(['company_id' => $company->id, 'status' => 'active']);
    $department->update(['head_id' => $head->id]);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'supervisor_id' => $head->id,
        'status' => 'active',
    ]);
    $hod = User::factory()->create(['company_id' => $company->id, 'employee_id' => $head->id]);
    $worker = User::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);
    $hr = User::factory()->create(['company_id' => $company->id]);

    foreach ([[$head, $hod], [$employee, $worker]] as [$person, $user]) {
        EmployeePortalAccess::query()->create([
            'employee_id' => $person->id,
            'user_id' => $user->id,
            'display_name' => $person->displayName(),
            'status' => EmployeePortalAccess::STATUS_ACTIVE,
        ]);
    }

    foreach ([$hod->id => 'people_hod', $hr->id => 'people_hr', $worker->id => 'people_employee'] as $userId => $role) {
        PrincipalRole::query()->create([
            'company_id' => $company->id,
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $userId,
            'role_id' => Role::query()->whereNull('company_id')->where('code', $role)->valueOrFail('id'),
        ]);
    }

    return [
        'tenant' => (int) $tenant->id,
        'company' => (int) $company->id,
        'hod' => $hod,
        'hr' => $hr,
        'employee' => $worker,
        'owner' => new WorkforceSubject(
            (int) $tenant->id,
            (int) $company->id,
            WorkforceResourceType::Employee,
            (string) $employee->id,
        ),
    ];
}

function proposedKpi(array $fixture, bool $confidential = false): KpiRecord
{
    return app(KpiRecordService::class)->propose(
        $fixture['hod'],
        $fixture['company'],
        $fixture['owner'],
        'delivery-quality',
        'Accepted deliveries / total deliveries',
        'At least 98%',
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2026-03-31'),
        ['ops:delivery-quality:v1'],
        $confidential,
    );
}

test('a KPI preserves its owner measure target period evidence and reviewed publication outcome', function (): void {
    $fixture = kpiFixture();
    $service = app(KpiRecordService::class);
    $record = proposedKpi($fixture);

    expect($record->owner_subject_type)->toBe(WorkforceResourceType::Employee->value)
        ->and($record->owner_subject_id)->toBe($fixture['owner']->stableId)
        ->and($record->measure)->toBe('Accepted deliveries / total deliveries')
        ->and($record->target)->toBe('At least 98%')
        ->and($record->period_start->toDateString())->toBe('2026-01-01')
        ->and($record->period_end->toDateString())->toBe('2026-03-31')
        ->and($record->evidence_references)->toBe(['ops:delivery-quality:v1']);

    $service->review($fixture['hr'], $fixture['company'], $record->id, 'Approved for communication.');
    $service->publishToEmployee($fixture['hr'], $fixture['company'], $record->id);
    $published = $service->readForEmployee($fixture['employee'], $fixture['company'], $record->id);

    expect($published->status)->toBe(KpiRecord::PUBLISHED)
        ->and($published->review_outcome)->toBe('Approved for communication.')
        ->and($published->published_at)->not->toBeNull();
});

test('a KPI proposal refuses a missing tenant before writing', function (): void {
    $fixture = kpiFixture();
    app(TenantContext::class)->clear();

    expect(fn () => proposedKpi($fixture))->toThrow(KpiRecordException::class, 'tenant context is required');
    app(TenantContext::class)->set($fixture['tenant']);
    expect(KpiRecord::query()->forCompany($fixture['tenant'], $fixture['company'])->count())->toBe(0);
});

test('a KPI proposal refuses an actor without the HOD capability', function (): void {
    $fixture = kpiFixture();

    expect(fn () => app(KpiRecordService::class)->propose(
        $fixture['employee'],
        $fixture['company'],
        $fixture['owner'],
        'delivery-quality',
        'Accepted deliveries',
        '98%',
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2026-03-31'),
    ))->toThrow(AuthorizationDeniedException::class);
});

test('a KPI proposal refuses another company and its owner subject', function (): void {
    $fixture = kpiFixture();
    $sibling = Company::factory()->create(['tenant_id' => $fixture['tenant'], 'status' => 'active']);
    $otherEmployee = Employee::factory()->create(['company_id' => $sibling->id, 'status' => 'active']);
    $owner = new WorkforceSubject(
        $fixture['tenant'],
        (int) $sibling->id,
        WorkforceResourceType::Employee,
        (string) $otherEmployee->id,
    );

    expect(fn () => app(KpiRecordService::class)->propose(
        $fixture['hod'],
        (int) $sibling->id,
        $owner,
        'foreign',
        'Foreign measure',
        '1',
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2026-03-31'),
    ))->toThrow(KpiRecordException::class, 'may not access KPI records for this company');
});

test('an employee cannot read a confidential KPI even when it names that employee', function (): void {
    $fixture = kpiFixture();
    $record = proposedKpi($fixture, confidential: true);
    $record->forceFill(['status' => KpiRecord::PUBLISHED, 'published_at' => now()])->save();

    expect(fn () => app(KpiRecordService::class)->readForEmployee(
        $fixture['employee'],
        $fixture['company'],
        $record->id,
    ))->toThrow(KpiRecordException::class, 'not published for the employee');
});

test('KPI records require an explicit company scope', function (): void {
    $fixture = kpiFixture();
    proposedKpi($fixture);

    expect(fn () => KpiRecord::query()->forTenant($fixture['tenant'])->get())
        ->toThrow(MissingCompanyScopeException::class);
});
