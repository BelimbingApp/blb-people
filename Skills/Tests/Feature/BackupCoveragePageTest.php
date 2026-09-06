<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentCycle;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\AssessmentResultBand;
use App\Domains\People\Skills\Enums\AssessmentStatus;
use App\Domains\People\Skills\Enums\HodVerification;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Livewire\BackupCoverage\Index;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\AssessmentWorkflowContext;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use Livewire\Livewire;

/**
 * 0007-b: HR reads, per department, how many people actually cover each
 * critical skill, and which ones rest on a single person.
 *
 * "Covers" is a deliberately narrow word here: at or above the required level,
 * still valid, in this company. Every test below is about something that looks
 * like cover and is not.
 *
 * Self-contained: helpers are prefixed cover and live here.
 *
 * @return array<string, mixed>
 */
function coverFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Coverage Tenant'], ['name' => 'Coverage Company', 'status' => 'active']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $unit = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'OPS', 'name' => 'Operations', 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $type = DepartmentType::query()->create([
        'code' => 'ops-cover', 'name' => 'Operations', 'category' => 'operational', 'is_active' => true,
    ]);
    $department = Department::query()->create([
        'company_id' => $companyId, 'department_type_id' => $type->id, 'status' => 'active',
    ]);

    $hr = User::factory()->create(['company_id' => $companyId]);
    $nobody = User::factory()->create(['company_id' => $companyId]);
    PrincipalRole::query()->create([
        'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $hr->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_hr')->valueOrFail('id'),
    ]);

    $sibling = Company::factory()->create(['tenant_id' => $tenantId, 'name' => 'Sibling Co', 'status' => 'active']);

    return compact('tenantId', 'companyId', 'hr', 'nobody', 'unit', 'department', 'sibling');
}

function coverEmployee(array $f, string $name, ?int $companyId = null): Employee
{
    $companyId ??= $f['companyId'];
    $employee = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $companyId === $f['companyId'] ? $f['department']->id : null,
        'full_name' => $name, 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    if ($companyId === $f['companyId']) {
        EmployeeWorkProfile::query()->create(['employee_id' => $employee->id, 'organization_unit_id' => $f['unit']->id]);
    }

    return $employee;
}

function coverSkill(array $f, int $companyId): int
{
    $existing = Skill::query()->forCompany($f['tenantId'], $companyId)->where('code', 'isolation.energy')->first();
    if ($existing !== null) {
        return (int) $existing->id;
    }
    $category = app(SkillCatalogStore::class)->defineCategory($companyId, 'safety', 'Safety');

    return (int) app(SkillCatalogStore::class)->defineSkill($companyId, new SkillDraft(
        code: 'isolation.energy', name: 'Energy isolation',
        definition: 'Isolate stored energy before maintenance.',
        categoryId: (int) $category->id, defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ))->id;
}

function coverScore(
    array $f,
    Employee $employee,
    int $current,
    int $required = 3,
    RequirementCriticality $criticality = RequirementCriticality::Critical,
    ?string $validUntil = null,
    ?int $companyId = null,
): EmployeeSkillScore {
    $companyId ??= $f['companyId'];
    $skillId = coverSkill($f, $companyId);
    $assessment = AssessmentWorkflowContext::runStoreMutation(static fn (): SkillAssessment => SkillAssessment::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $companyId,
        'employee_entity_id' => $employee->id, 'skill_id' => $skillId,
        'requirement_reference' => 'fixture.safety', 'requirement_version' => 2,
        'required_level' => $required, 'criticality' => $criticality, 'weight_percent' => 100,
        'mandatory_gate' => true, 'assessed_level' => $current, 'gap' => max($required - $current, 0),
        'weighted_gap' => max($required - $current, 0) * 100, 'priority_score' => max($required - $current, 0) * 300,
        'result_band' => AssessmentResultBand::fromGap(max($required - $current, 0), $current, $required),
        'method' => AssessmentMethod::DirectObservation, 'cycle' => AssessmentCycle::Annual,
        'status' => AssessmentStatus::Draft, 'evidence' => 'Observed task.', 'assessed_at' => now()->subDays(3),
        'assessor_user_id' => 9, 'hod_verification' => HodVerification::Pending,
    ]));

    return EmployeeSkillScore::query()->forCompany($f['tenantId'], $companyId)->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $companyId,
        'employee_entity_id' => $employee->id, 'skill_id' => $skillId,
        'source_assessment_id' => $assessment->id,
        'requirement_reference' => 'fixture.safety', 'requirement_version' => 2,
        'required_level' => $required, 'current_level' => $current,
        'gap' => max($required - $current, 0), 'mandatory_gate' => true,
        'criticality' => $criticality, 'assessed_at' => now()->subDays(3),
        'valid_until' => $validUntil,
    ]);
}

function coverRows(array $f): array
{
    return Livewire::actingAs($f['hr'])->test(Index::class)->viewData('rows');
}

test('a critical skill held by one person is counted once and flagged a single point of failure', function (): void {
    $f = coverFixture();
    coverScore($f, coverEmployee($f, 'Only Holder'), current: 4);

    $row = collect(coverRows($f))->firstWhere('skill', 'Energy isolation');

    expect($row['covered'])->toBe(1)
        ->and($row['single_point_of_failure'])->toBeTrue();
});

test('a second qualified holder clears the single-point-of-failure marker', function (): void {
    $f = coverFixture();
    coverScore($f, coverEmployee($f, 'First Holder'), current: 4);
    coverScore($f, coverEmployee($f, 'Second Holder'), current: 3);

    $row = collect(coverRows($f))->firstWhere('skill', 'Energy isolation');

    expect($row['covered'])->toBe(2)
        ->and($row['single_point_of_failure'])->toBeFalse();
});

test('someone below the required level is not cover', function (): void {
    $f = coverFixture();
    coverScore($f, coverEmployee($f, 'Qualified'), current: 3);
    coverScore($f, coverEmployee($f, 'Still Learning'), current: 2);

    $row = collect(coverRows($f))->firstWhere('skill', 'Energy isolation');

    expect($row['covered'])->toBe(1)
        ->and($row['single_point_of_failure'])->toBeTrue();
});

test('an expired qualification is not cover', function (): void {
    $f = coverFixture();
    coverScore($f, coverEmployee($f, 'Current'), current: 4);
    coverScore($f, coverEmployee($f, 'Lapsed'), current: 4, validUntil: now()->subDay()->toDateString());

    // A certificate that ran out is not somebody you can call at 2am.
    $row = collect(coverRows($f))->firstWhere('skill', 'Energy isolation');

    expect($row['covered'])->toBe(1)
        ->and($row['single_point_of_failure'])->toBeTrue();
});

test('another company\'s qualified employee is never cover here', function (): void {
    $f = coverFixture();
    coverScore($f, coverEmployee($f, 'Ours'), current: 4);

    // Deliberately against *our* skill id, filed under the sibling company —
    // the cross-company reference the company scope exists to refuse. Giving
    // the sibling its own skill row would have let the skill lookup separate
    // them and the score scope would never have been tested.
    $theirs = coverEmployee($f, 'Theirs', companyId: (int) $f['sibling']->id);
    $ourSkillId = coverSkill($f, $f['companyId']);
    $assessment = AssessmentWorkflowContext::runStoreMutation(static fn (): SkillAssessment => SkillAssessment::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => (int) $f['sibling']->id,
        'employee_entity_id' => $theirs->id, 'skill_id' => $ourSkillId,
        'requirement_reference' => 'fixture.safety', 'requirement_version' => 2,
        'required_level' => 3, 'criticality' => RequirementCriticality::Critical, 'weight_percent' => 100,
        'mandatory_gate' => true, 'assessed_level' => 4, 'gap' => 0,
        'weighted_gap' => 0, 'priority_score' => 0,
        'result_band' => AssessmentResultBand::fromGap(0, 4, 3),
        'method' => AssessmentMethod::DirectObservation, 'cycle' => AssessmentCycle::Annual,
        'status' => AssessmentStatus::Draft, 'evidence' => 'Observed task.', 'assessed_at' => now()->subDays(3),
        'assessor_user_id' => 9, 'hod_verification' => HodVerification::Pending,
    ]));
    EmployeeSkillScore::query()->forCompany($f['tenantId'], (int) $f['sibling']->id)->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => (int) $f['sibling']->id,
        'employee_entity_id' => $theirs->id, 'skill_id' => $ourSkillId,
        'source_assessment_id' => $assessment->id,
        'requirement_reference' => 'fixture.safety', 'requirement_version' => 2,
        'required_level' => 3, 'current_level' => 4, 'gap' => 0, 'mandatory_gate' => true,
        'criticality' => RequirementCriticality::Critical, 'assessed_at' => now()->subDays(3),
    ]);

    $row = collect(coverRows($f))->firstWhere('skill', 'Energy isolation');

    expect($row['covered'])->toBe(1)
        ->and($row['single_point_of_failure'])->toBeTrue()
        ->and($row['holders'])->toBe(['Ours']);
});

test('non-critical skills are not listed', function (): void {
    $f = coverFixture();
    coverScore($f, coverEmployee($f, 'Holder'), current: 4, criticality: RequirementCriticality::Development);

    expect(coverRows($f))->toBe([]);
});

test('the row names who covers the skill', function (): void {
    $f = coverFixture();
    coverScore($f, coverEmployee($f, 'Named Holder'), current: 4);

    $row = collect(coverRows($f))->firstWhere('skill', 'Energy isolation');

    expect($row['holders'])->toBe(['Named Holder']);
});

test('the page is refused without the coverage capability', function (): void {
    $f = coverFixture();
    coverScore($f, coverEmployee($f, 'Holder'), current: 4);

    Livewire::actingAs($f['nobody'])->test(Index::class)->assertForbidden();
});
