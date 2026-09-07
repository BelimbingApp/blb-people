<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentCycle;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\AssessmentResultBand;
use App\Domains\People\Skills\Enums\AssessmentStatus;
use App\Domains\People\Skills\Enums\HodVerification;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Livewire\TeamGaps\Index as TeamGaps;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Models\SkillReassessmentRequest;
use App\Domains\People\Skills\Services\AssessmentWorkflowContext;
use App\Domains\People\Skills\Services\SkillAudienceAssignmentStore;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use Livewire\Livewire;

/**
 * 0006-b: an HOD requests a reassessment for one direct report's skill from
 * the team gaps page. One open request per employee and skill; the employee
 * comes from the HOD's visible set, never from the request.
 *
 * Self-contained: helpers are prefixed rr and live here.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function rrFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Reassessment Tenant'], ['name' => 'Reassessment Company', 'status' => 'active']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $unit = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'RR-OPS', 'name' => 'Reassessment operations', 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $type = DepartmentType::query()->create([
        'code' => 'rr-ops', 'name' => 'Reassessment operations', 'category' => 'operational', 'is_active' => true,
    ]);
    $department = Department::query()->create(['company_id' => $companyId, 'department_type_id' => $type->id, 'status' => 'active']);

    $head = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id,
        'full_name' => 'Reassessment Head', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $department->update(['head_id' => $head->id]);
    EmployeeWorkProfile::query()->create(['employee_id' => $head->id, 'organization_unit_id' => $unit->id]);

    $report = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id, 'supervisor_id' => $head->id,
        'full_name' => 'Reassessment Report', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    EmployeeWorkProfile::query()->create(['employee_id' => $report->id, 'organization_unit_id' => $unit->id]);

    $outsider = Employee::factory()->create([
        'company_id' => $companyId, 'full_name' => 'Outside Employee', 'status' => 'active', 'employee_type' => 'full_time',
    ]);

    $hr = User::factory()->create(['company_id' => $companyId]);
    $hod = User::factory()->create(['company_id' => $companyId, 'employee_id' => $head->id]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $head->id, 'user_id' => $hod->id,
        'display_name' => 'Reassessment Head', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    foreach ([[$hr, 'people_hr'], [$hod, 'people_hod']] as [$actor, $code]) {
        PrincipalRole::query()->create([
            'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
            'principal_id' => $actor->id,
            'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->valueOrFail('id'),
        ]);
    }
    app(SkillAudienceAssignmentStore::class)->confirmActor($hr, $hod, $companyId, (int) $head->id, 'review:reassessment-hod');

    return compact('tenantId', 'companyId', 'hod', 'head', 'report', 'outsider');
}

function rrSkill(array $f): int
{
    $category = app(SkillCatalogStore::class)->defineCategory($f['companyId'], 'rr-safety', 'Reassessment Safety');

    return (int) app(SkillCatalogStore::class)->defineSkill($f['companyId'], new SkillDraft(
        code: 'rr.isolation', name: 'RR isolation', definition: 'Isolate before maintenance.',
        categoryId: (int) $category->id, defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ))->id;
}

function rrScore(array $f, Employee $employee, int $skillId): EmployeeSkillScore
{
    $assessment = AssessmentWorkflowContext::runStoreMutation(static fn (): SkillAssessment => SkillAssessment::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'],
        'employee_entity_id' => $employee->id, 'skill_id' => $skillId,
        'requirement_reference' => 'fixture.rr', 'requirement_version' => 1,
        'required_level' => 4, 'criticality' => RequirementCriticality::Critical, 'weight_percent' => 100,
        'mandatory_gate' => true, 'assessed_level' => 2, 'gap' => 2,
        'weighted_gap' => 200, 'priority_score' => 600,
        'result_band' => AssessmentResultBand::fromGap(2, 2, 4),
        'method' => AssessmentMethod::DirectObservation, 'cycle' => AssessmentCycle::Annual,
        'status' => AssessmentStatus::Draft, 'evidence' => 'Observed task.', 'assessed_at' => now()->subDays(3),
        'assessor_user_id' => 9, 'hod_verification' => HodVerification::Pending,
    ]));

    return EmployeeSkillScore::query()->forCompany($f['tenantId'], $f['companyId'])->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'],
        'employee_entity_id' => $employee->id, 'skill_id' => $skillId,
        'source_assessment_id' => $assessment->id, 'requirement_reference' => 'fixture.rr',
        'requirement_version' => 1, 'required_level' => 4, 'current_level' => 2, 'gap' => 2,
        'mandatory_gate' => true, 'criticality' => RequirementCriticality::Critical,
        'assessed_at' => now()->subDays(3),
    ]);
}

function rrOpenCount(array $f, Employee $employee, int $skillId): int
{
    return SkillReassessmentRequest::query()->forCompany($f['tenantId'], $f['companyId'])
        ->where('employee_entity_id', $employee->id)->where('skill_id', $skillId)
        ->where('status', 'pending')->count();
}

it('creates one pending request and marks the gap row', function (): void {
    $this->withoutVite();
    $f = rrFixture();
    $skillId = rrSkill($f);
    rrScore($f, $f['report'], $skillId);

    Livewire::actingAs($f['hod'])
        ->test(TeamGaps::class)
        ->set('reasons.'.$f['report']->id.'.'.$skillId, 'Recheck after coaching.')
        ->call('requestReassessment', $f['report']->id, $skillId)
        ->assertSee('Reassessment pending');

    expect(rrOpenCount($f, $f['report'], $skillId))->toBe(1);
});

it('refuses a duplicate open request for the same employee and skill', function (): void {
    $this->withoutVite();
    $f = rrFixture();
    $skillId = rrSkill($f);
    rrScore($f, $f['report'], $skillId);

    $page = Livewire::actingAs($f['hod'])->test(TeamGaps::class)
        ->set('reasons.'.$f['report']->id.'.'.$skillId, 'First request.');
    $page->call('requestReassessment', $f['report']->id, $skillId);

    Livewire::actingAs($f['hod'])
        ->test(TeamGaps::class)
        ->set('reasons.'.$f['report']->id.'.'.$skillId, 'Second request.')
        ->call('requestReassessment', $f['report']->id, $skillId)
        ->assertSee('already has an open reassessment request');

    expect(rrOpenCount($f, $f['report'], $skillId))->toBe(1);
});

it('refuses a request for an employee outside the HOD department', function (): void {
    $this->withoutVite();
    $f = rrFixture();
    $skillId = rrSkill($f);
    rrScore($f, $f['outsider'], $skillId);

    Livewire::actingAs($f['hod'])
        ->test(TeamGaps::class)
        ->set('reasons.'.$f['outsider']->id.'.'.$skillId, 'Sneaky request.')
        ->call('requestReassessment', $f['outsider']->id, $skillId)
        ->assertSee('unavailable in the current scope');

    expect(rrOpenCount($f, $f['outsider'], $skillId))->toBe(0);
});
