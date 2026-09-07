<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentCycle;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\AssessmentResultBand;
use App\Domains\People\Skills\Enums\AssessmentStatus;
use App\Domains\People\Skills\Enums\HodVerification;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\AssessmentWorkflowContext;
use App\Domains\People\Skills\Services\CriticalSkillBackupCoverage;
use App\Domains\People\Skills\Services\SkillCatalogStore;

/**
 * 0007-c: for each critical skill in a department, are there enough people at
 * or above the required level to cover it if one of them is away?
 *
 * 0007-b already answers "how many hold this" company-wide and calls one
 * holder a single point of failure. What is missing is the department the
 * question is actually asked in, and a minimum a tenant can set: two is a
 * sensible default and a poor rule for a team of three.
 *
 * Self-contained: helpers are prefixed backup and live here.
 *
 * @return array<string, mixed>
 */
function backupFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(
        ['name' => 'Backup Tenant'],
        ['name' => 'Backup Company', 'status' => 'active'],
    );
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);

    $type = DepartmentType::query()->create([
        'code' => 'ops-backup', 'name' => 'Operations', 'category' => 'operational', 'is_active' => true,
    ]);
    $department = Department::query()->create([
        'company_id' => $companyId, 'department_type_id' => $type->id, 'status' => 'active',
    ]);
    // A company may hold each department type once, so a second department
    // needs a second type.
    $otherType = DepartmentType::query()->create([
        'code' => 'maint-backup', 'name' => 'Maintenance', 'category' => 'operational', 'is_active' => true,
    ]);
    $otherDepartment = Department::query()->create([
        'company_id' => $companyId, 'department_type_id' => $otherType->id, 'status' => 'active',
    ]);
    $sibling = Company::factory()->create(['tenant_id' => $tenantId, 'name' => 'Sibling Co', 'status' => 'active']);

    return compact('tenantId', 'companyId', 'department', 'otherDepartment', 'sibling');
}

function backupEmployee(array $f, string $name, ?Department $department = null, ?int $companyId = null): Employee
{
    return Employee::factory()->create([
        'company_id' => $companyId ?? $f['companyId'],
        'department_id' => $department?->id ?? ($companyId === null ? $f['department']->id : null),
        'full_name' => $name, 'status' => 'active', 'employee_type' => 'full_time',
    ]);
}

function backupSkill(array $f, int $companyId): int
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

function backupScore(
    array $f,
    Employee $employee,
    int $current,
    int $required = 3,
    RequirementCriticality $criticality = RequirementCriticality::Critical,
    ?string $validUntil = null,
    ?int $companyId = null,
): EmployeeSkillScore {
    $companyId ??= $f['companyId'];
    $skillId = backupSkill($f, $companyId);
    $gap = max($required - $current, 0);
    $assessment = AssessmentWorkflowContext::runStoreMutation(static fn (): SkillAssessment => SkillAssessment::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $companyId,
        'employee_entity_id' => $employee->id, 'skill_id' => $skillId,
        'requirement_reference' => 'fixture.safety', 'requirement_version' => 2,
        'required_level' => $required, 'criticality' => $criticality, 'weight_percent' => 100,
        'mandatory_gate' => true, 'assessed_level' => $current, 'gap' => $gap,
        'weighted_gap' => $gap * 100, 'priority_score' => $gap * 300,
        'result_band' => AssessmentResultBand::fromGap($gap, $current, $required),
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
        'gap' => $gap, 'mandatory_gate' => true,
        'criticality' => $criticality, 'assessed_at' => now()->subDays(3),
        'valid_until' => $validUntil,
    ]);
}

/** @return array<string, mixed>|null */
function backupRow(array $f, ?Department $department = null): ?array
{
    $rows = app(CriticalSkillBackupCoverage::class)
        ->rows($f['tenantId'], $f['companyId'], $department?->id);

    return collect($rows)->firstWhere('skill', 'Energy isolation');
}

test('a critical skill with the backup minimum of holders is covered, and one fewer is not', function (): void {
    $f = backupFixture();
    backupScore($f, backupEmployee($f, 'First Holder'), current: 4);
    backupScore($f, backupEmployee($f, 'Second Holder'), current: 3);

    $row = backupRow($f, $f['department']);

    expect($row)->not->toBeNull()
        ->and($row['required_level'])->toBe(3)
        ->and($row['holders'])->toBe(2)
        ->and($row['minimum'])->toBe(2)
        ->and($row['covered'])->toBeTrue();
});

test('one holder short of the minimum is not covered', function (): void {
    $f = backupFixture();
    backupScore($f, backupEmployee($f, 'Only Holder'), current: 4);

    $row = backupRow($f, $f['department']);

    expect($row['holders'])->toBe(1)
        ->and($row['covered'])->toBeFalse();
});

test('a lapsed score is a record of past competence, not cover now', function (): void {
    $f = backupFixture();
    backupScore($f, backupEmployee($f, 'Current Holder'), current: 4);
    backupScore($f, backupEmployee($f, 'Lapsed Holder'), current: 4, validUntil: now()->subDay()->toDateString());

    $row = backupRow($f, $f['department']);

    expect($row['holders'])->toBe(1)
        ->and($row['covered'])->toBeFalse();
});

test('a holder below the required level does not cover the skill', function (): void {
    $f = backupFixture();
    backupScore($f, backupEmployee($f, 'At Level'), current: 3);
    backupScore($f, backupEmployee($f, 'Below Level'), current: 2);

    expect(backupRow($f, $f['department'])['holders'])->toBe(1);
});

test('a non-critical skill never appears', function (): void {
    $f = backupFixture();
    backupScore($f, backupEmployee($f, 'Holder'), current: 4, criticality: RequirementCriticality::Essential);

    expect(backupRow($f, $f['department']))->toBeNull();
});

test('holders in another department or another company do not cover this department', function (): void {
    $f = backupFixture();
    backupScore($f, backupEmployee($f, 'Ours'), current: 4);
    backupScore($f, backupEmployee($f, 'Other Department', department: $f['otherDepartment']), current: 4);
    backupScore($f, backupEmployee($f, 'Sibling Company', companyId: (int) $f['sibling']->id), current: 4, companyId: (int) $f['sibling']->id);

    expect(backupRow($f, $f['department'])['holders'])->toBe(1);
});
