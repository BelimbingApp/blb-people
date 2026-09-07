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
use App\Domains\People\Skills\Livewire\TeamGaps\Index;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\AssessmentWorkflowContext;
use App\Domains\People\Skills\Services\SkillAudienceAssignmentStore;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingPriority;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Models\TrainingRequest;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * 0007-a: an HOD reads the critical-skill gaps of their own direct reports,
 * with whether something already targets each gap. The subject set comes from
 * the acting user's department, never from the request, and the page is a read.
 *
 * Self-contained: helpers are prefixed gaps and live here.
 *
 * @return array<string, mixed>
 */
function gapsFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Team Gaps Tenant'], ['name' => 'Team Gaps Company', 'status' => 'active']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $units = [];
    $departments = [];
    foreach ([['OPS', 'Operations'], ['FIN', 'Finance']] as [$code, $name]) {
        $units[$code] = PeopleReferenceEntry::query()->create([
            'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
            'code' => $code, 'name' => $name, 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
        ]);
        $type = DepartmentType::query()->create([
            'code' => strtolower($code).'-gaps', 'name' => $name, 'category' => 'operational', 'is_active' => true,
        ]);
        $departments[$code] = Department::query()->create([
            'company_id' => $companyId, 'department_type_id' => $type->id, 'status' => 'active',
        ]);
    }

    $head = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $departments['OPS']->id,
        'full_name' => 'Operations Head', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $departments['OPS']->update(['head_id' => $head->id]);
    EmployeeWorkProfile::query()->create(['employee_id' => $head->id, 'organization_unit_id' => $units['OPS']->id]);

    $report = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $departments['OPS']->id, 'supervisor_id' => $head->id,
        'full_name' => 'Direct Report', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    EmployeeWorkProfile::query()->create(['employee_id' => $report->id, 'organization_unit_id' => $units['OPS']->id]);

    $peer = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $departments['FIN']->id,
        'full_name' => 'Peer Department Employee', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    EmployeeWorkProfile::query()->create(['employee_id' => $peer->id, 'organization_unit_id' => $units['FIN']->id]);

    $hr = User::factory()->create(['company_id' => $companyId]);
    $hod = User::factory()->create(['company_id' => $companyId, 'employee_id' => $head->id]);
    $nobody = User::factory()->create(['company_id' => $companyId]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $head->id, 'user_id' => $hod->id,
        'display_name' => 'Operations Head', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    foreach ([[$hr, 'people_hr'], [$hod, 'people_hod']] as [$actor, $code]) {
        PrincipalRole::query()->create([
            'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
            'principal_id' => $actor->id,
            'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->valueOrFail('id'),
        ]);
    }
    app(SkillAudienceAssignmentStore::class)->confirmActor($hr, $hod, $companyId, (int) $head->id, 'review:team-gaps-hod');

    return compact('tenantId', 'companyId', 'hod', 'nobody', 'head', 'report', 'peer');
}

function gapsSkill(array $f): int
{
    $existing = Skill::query()->forCompany($f['tenantId'], $f['companyId'])->where('code', 'isolation.energy')->first();
    if ($existing !== null) {
        return (int) $existing->id;
    }

    $category = app(SkillCatalogStore::class)->defineCategory($f['companyId'], 'safety', 'Safety');

    return (int) app(SkillCatalogStore::class)->defineSkill($f['companyId'], new SkillDraft(
        code: 'isolation.energy',
        name: 'Energy isolation',
        definition: 'Isolate stored energy before maintenance.',
        categoryId: (int) $category->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ))->id;
}

/**
 * The score row's source assessment is NOT NULL and foreign-keyed, so the
 * fixture builds a real one. This file lives under Skills/Tests, which is the
 * only place the assessment workflow authority may be used directly.
 */
function gapsScore(array $f, Employee $employee, int $required, int $current, RequirementCriticality $criticality = RequirementCriticality::Critical): EmployeeSkillScore
{
    $skillId = gapsSkill($f);
    $assessment = AssessmentWorkflowContext::runStoreMutation(static fn (): SkillAssessment => SkillAssessment::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'],
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

    return EmployeeSkillScore::query()->forCompany($f['tenantId'], $f['companyId'])->create([
        'tenant_id' => $f['tenantId'],
        'company_entity_id' => $f['companyId'],
        'employee_entity_id' => $employee->id,
        'skill_id' => $skillId,
        'source_assessment_id' => $assessment->id,
        'requirement_reference' => 'fixture.safety',
        'requirement_version' => 2,
        'required_level' => $required,
        'current_level' => $current,
        'gap' => max($required - $current, 0),
        'mandatory_gate' => true,
        'criticality' => $criticality,
        'assessed_at' => now()->subDays(3),
    ]);
}

function gapsRows(array $f): array
{
    return Livewire::actingAs($f['hod'])->test(Index::class)->viewData('rows');
}

test('an HOD sees a direct report\'s critical gap and not a peer department\'s', function (): void {
    $f = gapsFixture();
    gapsScore($f, $f['report'], required: 4, current: 2);
    gapsScore($f, $f['peer'], required: 4, current: 1);

    $names = collect(gapsRows($f))->pluck('employee')->all();

    expect($names)->toContain('Direct Report')
        ->and($names)->not->toContain('Peer Department Employee');
});

test('a skill at or above the required level is not a gap', function (): void {
    $f = gapsFixture();
    gapsScore($f, $f['report'], required: 3, current: 3);

    expect(gapsRows($f))->toBe([]);
});

test('only critical skills are listed', function (): void {
    $f = gapsFixture();
    gapsScore($f, $f['report'], required: 4, current: 1, criticality: RequirementCriticality::Development);

    // The page is the critical-skill view; a non-critical shortfall belongs to
    // the planning page, not the gap list.
    expect(gapsRows($f))->toBe([]);
});

test('a gap carries the latest assessment date and is marked unplanned by default', function (): void {
    $f = gapsFixture();
    gapsScore($f, $f['report'], required: 4, current: 2);

    $row = collect(gapsRows($f))->firstWhere('employee', 'Direct Report');

    expect($row['required_level'])->toBe(4)
        ->and($row['current_level'])->toBe(2)
        ->and($row['planned'])->toBeFalse()
        ->and($row['assessed_at'])->not->toBeNull();
});

test('the page is refused without the team-gaps capability', function (): void {
    $f = gapsFixture();
    gapsScore($f, $f['report'], required: 4, current: 2);

    Livewire::actingAs($f['nobody'])->test(Index::class)->assertForbidden();
});

test('a gap a training request already targets is marked planned', function (): void {
    $f = gapsFixture();
    $score = gapsScore($f, $f['report'], required: 4, current: 2);

    TrainingRequest::query()->forCompany($f['tenantId'], $f['companyId'])->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'],
        'request_key' => (string) Str::uuid(),
        'requestor_provider_id' => 'native',
        'requestor_subject_id' => (string) $f['report']->id,
        'department_provider_id' => 'native',
        'department_subject_id' => (string) $f['report']->department_id,
        'need_source' => TrainingNeedSource::SkillGap->value,
        'need' => 'Close the isolation gap.',
        'learning_objective' => 'Isolate energy to the governed standard.',
        'expected_result' => 'Observed isolation without a stop.',
        'created_by_user_id' => $f['hod']->id,
        // The link is the assessment that established this gap, not the skill:
        // two people can be short on the same skill for different reasons.
        'skill_gap_assessment_id' => $score->source_assessment_id,
        'priority' => TrainingPriority::High->value,
        'status' => TrainingRequestStatus::PendingHod->value,
    ]);

    $row = collect(gapsRows($f))->firstWhere('employee', 'Direct Report');

    expect($row['planned'])->toBeTrue();
});

test('a rejected request does not count as targeting the gap', function (): void {
    $f = gapsFixture();
    $score = gapsScore($f, $f['report'], required: 4, current: 2);

    TrainingRequest::query()->forCompany($f['tenantId'], $f['companyId'])->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'],
        'request_key' => (string) Str::uuid(),
        'requestor_provider_id' => 'native',
        'requestor_subject_id' => (string) $f['report']->id,
        'department_provider_id' => 'native',
        'department_subject_id' => (string) $f['report']->department_id,
        'need_source' => TrainingNeedSource::SkillGap->value,
        'need' => 'Close the isolation gap.',
        'learning_objective' => 'Isolate energy to the governed standard.',
        'expected_result' => 'Observed isolation without a stop.',
        'created_by_user_id' => $f['hod']->id,
        'skill_gap_assessment_id' => $score->source_assessment_id,
        'priority' => TrainingPriority::High->value,
        'status' => TrainingRequestStatus::Rejected->value,
    ]);

    // A refused request left the gap exactly where it was.
    expect(collect(gapsRows($f))->firstWhere('employee', 'Direct Report')['planned'])->toBeFalse();
});

test('the HOD\'s own gap is not in the team list', function (): void {
    $f = gapsFixture();
    gapsScore($f, $f['head'], required: 4, current: 1);
    gapsScore($f, $f['report'], required: 4, current: 2);

    // This is the reports' page. A manager's own gap is their manager's to see,
    // and mixing it in makes the list quietly mean something else.
    $names = collect(gapsRows($f))->pluck('employee')->all();

    expect($names)->toContain('Direct Report')
        ->and($names)->not->toContain('Operations Head');
});
