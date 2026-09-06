<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Performance\Data\KpiResultValue;
use App\Domains\People\Performance\Data\TeamKpiAttribution;
use App\Domains\People\Performance\Enums\KpiDirection;
use App\Domains\People\Performance\Enums\KpiValueState;
use App\Domains\People\Performance\Exceptions\KpiRecordException;
use App\Domains\People\Performance\Models\KpiDefinition;
use App\Domains\People\Performance\Models\JobDescription;
use App\Domains\People\Performance\Models\KpiRecord;
use App\Domains\People\Performance\Services\KpiRecordService;
use App\Domains\People\Performance\Services\OrganisationPerformanceDetail;
use App\Domains\People\Performance\Enums\JobDescriptionStatus;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Exceptions\MissingCompanyScopeException;

afterEach(fn () => app(TenantContext::class)->clear());

test('performance capabilities are discovered from the module', function (): void {
    expect(config('authz.capabilities'))->toContain('people.performance.kpi.submit');
});

/** @return array{tenant: int, company: int, hod: User, hr: User, employee: User, owner: WorkforceSubject, organization: WorkforceSubject} */
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
    $organization = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id,
        'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'kpi-unit',
        'name' => 'KPI Unit',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $head = Employee::factory()->create(['company_id' => $company->id, 'status' => 'active']);
    $department->update(['head_id' => $head->id]);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'supervisor_id' => $head->id,
        'status' => 'active',
    ]);
    EmployeeWorkProfile::query()->create([
        'employee_id' => $employee->id,
        'organization_unit_id' => $organization->id,
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
        'organization' => new WorkforceSubject(
            (int) $tenant->id,
            (int) $company->id,
            WorkforceResourceType::OrganizationUnit,
            (string) $organization->id,
        ),
    ];
}

function proposedKpi(array $fixture, bool $confidential = false): KpiRecord
{
    $service = app(KpiRecordService::class);
    $definition = $service->define(
        $fixture['hod'],
        $fixture['company'],
        $fixture['owner'],
        'delivery-quality',
        1,
        'Delivery quality',
        'Keep accepted deliveries reliable.',
        'percent',
        'Accepted deliveries / total deliveries',
        'ops:delivery-quality',
        KpiDirection::HigherIsBetter,
        null,
        'ratio-v1',
        2,
        'Higher is better.',
    );

    return $service->propose(
        $fixture['hod'],
        $fixture['company'],
        $fixture['owner'],
        $definition->id,
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

    $definition = KpiDefinition::query()->forCompany($fixture['tenant'], $fixture['company'])->findOrFail($record->kpi_definition_id);

    expect($record->owner_subject_type)->toBe(WorkforceResourceType::Employee->value)
        ->and($record->owner_subject_id)->toBe($fixture['owner']->stableId)
        ->and($definition->measure)->toBe('Accepted deliveries / total deliveries')
        ->and($definition->unit)->toBe('percent')
        ->and($definition->direction)->toBe(KpiDirection::HigherIsBetter)
        ->and($record->getAttributes())->not->toHaveKeys(['measure', 'unit', 'direction', 'rubric', 'calculation'])
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
        999999,
        '98%',
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2026-03-31'),
    ))->toThrow(AuthorizationDeniedException::class);
});

test('a KPI proposal refuses another company and its owner subject', function (): void {
    $fixture = kpiFixture();
    $definitionId = proposedKpi($fixture)->kpi_definition_id;
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
        $definitionId,
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
        ->toThrow(MissingCompanyScopeException::class)
        ->and(fn () => KpiDefinition::query()->forTenant($fixture['tenant'])->get())
        ->toThrow(MissingCompanyScopeException::class);
});

test('an approved target amendment preserves the original target and approval', function (): void {
    $fixture = kpiFixture();
    $service = app(KpiRecordService::class);
    $original = proposedKpi($fixture);
    $service->review($fixture['hr'], $fixture['company'], $original->id, 'Approved original target.');

    $replacement = $service->amendTarget(
        $fixture['hod'],
        $fixture['company'],
        $original->id,
        'At least 99%',
        new DateTimeImmutable('2026-02-01'),
        'Customer acceptance criteria changed.',
    );

    expect($original->refresh()->target)->toBe('At least 98%')
        ->and($original->review_outcome)->toBe('Approved original target.')
        ->and($replacement->target)->toBe('At least 99%')
        ->and($replacement->target_version)->toBe(2)
        ->and($replacement->supersedes_assignment_id)->toBe($original->id)
        ->and($replacement->amendment_reason)->toBe('Customer acceptance criteria changed.')
        ->and($replacement->effective_from->toDateString())->toBe('2026-02-01');
});

test('missing zero and zero denominator are distinct typed values', function (): void {
    $missing = new KpiResultValue(KpiValueState::Missing);
    $zero = new KpiResultValue(KpiValueState::Zero, 0.0);
    $zeroDenominator = new KpiResultValue(KpiValueState::ZeroDenominator);

    expect($missing->state)->toBe(KpiValueState::Missing)
        ->and($missing->value)->toBeNull()
        ->and($zero->state)->toBe(KpiValueState::Zero)
        ->and($zero->value)->toBe(0.0)
        ->and($zeroDenominator->state)->toBe(KpiValueState::ZeroDenominator)
        ->and(fn () => new KpiResultValue(KpiValueState::Value, 0.0))
        ->toThrow(KpiRecordException::class, 'does not match its declared state')
        ->and(fn () => new KpiResultValue(KpiValueState::Missing, 0.0))
        ->toThrow(KpiRecordException::class, 'does not match its declared state')
        ->and(fn () => new KpiResultValue(KpiValueState::ZeroDenominator, 0.0))
        ->toThrow(KpiRecordException::class, 'does not match its declared state');
});

test('team KPI attribution never copies or double counts people implicitly', function (): void {
    $fixture = kpiFixture();
    $service = app(KpiRecordService::class);
    $definitionId = proposedKpi($fixture)->kpi_definition_id;
    $team = $service->propose(
        $fixture['hod'],
        $fixture['company'],
        $fixture['organization'],
        $definitionId,
        'At least 98%',
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2026-03-31'),
        attribution: TeamKpiAttribution::notAttributed(),
    );
    $declared = $service->propose(
        $fixture['hod'],
        $fixture['company'],
        $fixture['organization'],
        $definitionId,
        'At least 98%',
        new DateTimeImmutable('2026-04-01'),
        new DateTimeImmutable('2026-06-30'),
        attribution: TeamKpiAttribution::declared([$fixture['owner']->stableId]),
    );

    expect($team->attributed_employee_subject_ids)->toBe([])
        ->and($declared->attributed_employee_subject_ids)->toBe([$fixture['owner']->stableId])
        ->and(fn () => TeamKpiAttribution::declared(['employee-1', 'employee-1']))
        ->toThrow(KpiRecordException::class, 'unique declared employee subjects');
});

test('an HOD can define a position KPI template only inside the managed department', function (): void {
    $fixture = kpiFixture();
    $position = PeopleReferenceEntry::query()->create([
        'company_id' => $fixture['company'],
        'type' => PeopleReferenceEntry::TYPE_JOB_TITLE,
        'parent_id' => (int) $fixture['organization']->stableId,
        'code' => 'managed-position',
        'name' => 'Managed Position',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $subject = new WorkforceSubject(
        $fixture['tenant'],
        $fixture['company'],
        WorkforceResourceType::Position,
        (string) $position->id,
    );

    $definition = app(KpiRecordService::class)->define(
        $fixture['hod'],
        $fixture['company'],
        $subject,
        'position-quality',
        1,
        'Position quality',
        'Set a reusable expectation for this position.',
        'percent',
        'Accepted work / total work',
        'ops:position-quality',
        KpiDirection::HigherIsBetter,
        null,
        'ratio-v1',
        2,
        'Higher is better.',
    );

    expect($definition->steward_subject_type)->toBe(WorkforceResourceType::Position->value)
        ->and($definition->steward_subject_id)->toBe((string) $position->id);
});

test('employee explorer detail returns the applicable published JD and released KPI with separately permitted evidence', function (): void {
    $fixture = kpiFixture();
    $position = PeopleReferenceEntry::query()->create([
        'company_id' => $fixture['company'],
        'type' => PeopleReferenceEntry::TYPE_JOB_TITLE,
        'code' => 'employee-position',
        'name' => 'Employee Position',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    EmployeeWorkProfile::query()->where('employee_id', $fixture['employee']->employee_id)
        ->update(['job_title_id' => $position->id]);
    JobDescription::query()->create([
        'tenant_id' => $fixture['tenant'],
        'company_entity_id' => $fixture['company'],
        'reference' => 'employee-position-jd',
        'position_stable_id' => (string) $position->id,
        'position_version' => 1,
        'version' => 2,
        'status' => JobDescriptionStatus::Published,
        'effective_from' => '2026-01-01',
        'purpose' => 'Own dependable delivery.',
        'responsibilities' => ['Deliver agreed outcomes'],
        'duties' => ['Review work evidence'],
        'authority' => 'Approve work inside the assigned remit.',
        'qualifications' => ['Relevant experience'],
        'competency_links' => [['requirement_profile_id' => 10, 'requirement_profile_version' => 3]],
        'published_at' => now(),
        'published_by_user_id' => $fixture['hr']->id,
    ]);
    $record = proposedKpi($fixture);
    app(KpiRecordService::class)->review($fixture['hr'], $fixture['company'], $record->id, 'Released outcome rationale.');
    app(KpiRecordService::class)->publishToEmployee($fixture['hr'], $fixture['company'], $record->id);

    foreach (['people.performance.job-description.view', 'people.performance.kpi.evidence.view'] as $capability) {
        PrincipalCapability::query()->create([
            'company_id' => $fixture['company'],
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $fixture['employee']->id,
            'capability_key' => $capability,
            'is_allowed' => true,
        ]);
    }

    $detail = app(OrganisationPerformanceDetail::class)->detail(
        Actor::forUser($fixture['employee']),
        $fixture['owner'],
        new DateTimeImmutable('2026-02-01'),
    );

    expect($detail->jobDescription->records[0]['version'])->toBe(2)
        ->and($detail->jobDescription->records[0]['authority'])->toBe('Approve work inside the assigned remit.')
        ->and($detail->performance->records[0]['status'])->toBe(KpiRecord::PUBLISHED)
        ->and($detail->performance->records[0]['released_outcome'])->toBe('Released outcome rationale.')
        ->and($detail->performance->records[0]['evidence_references'])->toBe(['ops:delivery-quality:v1'])
        ->and($detail->performance->records[0]['evidence_refusal'])->toBeNull();
});
