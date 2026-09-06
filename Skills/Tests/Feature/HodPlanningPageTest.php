<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Livewire\Planning\Index;
use App\Domains\People\Skills\Models\DevelopmentAction;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\SkillActorBinding;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\AssessmentWorkflowContext;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Training\Data\TrainingRequestDraft;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingPriority;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Services\TrainingRequestStore;
use Illuminate\Support\Str;
use Livewire\Livewire;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

/*
 * Self-contained: every helper is prefixed hodPlanning and lives here, so
 * this file never borrows fixtures from another test file.
 *
 * Alpha unit (HOD + two members, one open gap) and Beta unit (one member,
 * one open gap). The HOD heads Alpha's department; Beta is out of scope.
 *
 * @return array{tenant: mixed, company: mixed, unitA: PeopleReferenceEntry, unitB: PeopleReferenceEntry, hod: Employee, a1: Employee, a2: Employee, b1: Employee, skill: mixed, gapA: SkillAssessment, gapB: SkillAssessment}
 */
function hodPlanningFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(
        ['name' => 'HOD planning tenant'],
        ['name' => 'HOD Planning Co', 'status' => 'active'],
    );
    app(TenantContext::class)->set((int) $tenant->id);
    $companyId = (int) $company->id;
    $tag = Str::lower(Str::random(6));

    $unitA = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'hod-alpha-'.$tag, 'name' => 'Hod Alpha',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $unitB = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'hod-beta-'.$tag, 'name' => 'Hod Beta',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $positionA = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_JOB_TITLE,
        'code' => 'hod-role-a-'.$tag, 'name' => 'Hod Alpha Role',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE, 'parent_id' => $unitA->id,
    ]);
    $positionB = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_JOB_TITLE,
        'code' => 'hod-role-b-'.$tag, 'name' => 'Hod Beta Role',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE, 'parent_id' => $unitB->id,
    ]);

    $typeA = DepartmentType::query()->create([
        'code' => 'hod-a-'.$tag, 'name' => 'HOD Alpha Operations',
        'category' => 'operational', 'is_active' => true,
    ]);
    $typeB = DepartmentType::query()->create([
        'code' => 'hod-b-'.$tag, 'name' => 'HOD Beta Operations',
        'category' => 'operational', 'is_active' => true,
    ]);

    $hod = Employee::factory()->create(['company_id' => $companyId, 'status' => 'active', 'short_name' => 'Hod Head']);
    $a1 = Employee::factory()->create(['company_id' => $companyId, 'status' => 'active', 'short_name' => 'Hod Alpha One']);
    $a2 = Employee::factory()->create(['company_id' => $companyId, 'status' => 'active']);
    $b1 = Employee::factory()->create(['company_id' => $companyId, 'status' => 'active', 'short_name' => 'Hod Beta One']);

    $departmentA = Department::query()->create([
        'company_id' => $companyId, 'department_type_id' => $typeA->id,
        'head_id' => $hod->id, 'status' => 'active',
    ]);
    $departmentB = Department::query()->create([
        'company_id' => $companyId, 'department_type_id' => $typeB->id,
        'head_id' => $b1->id, 'status' => 'active',
    ]);

    foreach ([[$hod, $unitA, $positionA, $departmentA], [$a1, $unitA, $positionA, $departmentA],
        [$a2, $unitA, $positionA, $departmentA], [$b1, $unitB, $positionB, $departmentB]] as [$member, $unit, $position, $department]) {
        $member->update(['department_id' => $department->id]);
        EmployeeWorkProfile::query()->updateOrCreate(
            ['employee_id' => $member->id],
            ['organization_unit_id' => $unit->id, 'job_title_id' => $position->id],
        );
    }

    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($companyId, 'hod-'.$tag, 'HOD planning');
    $skill = $catalog->defineSkill($companyId, new SkillDraft(
        'hod-'.$tag.'.gap', 'HOD gap skill', 'A skill with open gaps.', (int) $category->id,
    ));

    $reviewer = Employee::factory()->create(['company_id' => $companyId, 'status' => 'active']);
    $gapA = hodPlanningGap((int) $tenant->id, $companyId, (int) $a1->id, (int) $reviewer->id, (int) $skill->id, 'hod.alpha', 4, 2);
    $gapB = hodPlanningGap((int) $tenant->id, $companyId, (int) $b1->id, (int) $reviewer->id, (int) $skill->id, 'hod.beta', 5, 1);

    return [
        'tenant' => $tenant, 'company' => $company,
        'unitA' => $unitA, 'unitB' => $unitB,
        'hod' => $hod, 'a1' => $a1, 'a2' => $a2, 'b1' => $b1,
        'skill' => $skill, 'gapA' => $gapA, 'gapB' => $gapB,
    ];
}

function hodPlanningGap(
    int $tenantId,
    int $companyId,
    int $employeeId,
    int $reviewerId,
    int $skillId,
    string $reference,
    int $required,
    int $assessed,
): SkillAssessment {
    return AssessmentWorkflowContext::runStoreMutation(function () use (
        $tenantId, $companyId, $employeeId, $reviewerId, $skillId, $reference, $required, $assessed,
    ): SkillAssessment {
        $assessment = SkillAssessment::query()->create([
            'tenant_id' => $tenantId, 'company_entity_id' => $companyId, 'employee_entity_id' => $employeeId,
            'skill_id' => $skillId, 'requirement_reference' => $reference, 'requirement_version' => 3,
            'required_level' => $required, 'assessed_level' => $assessed, 'gap' => $required - $assessed,
            'criticality' => 'critical', 'mandatory_gate' => true,
            'method' => 'direct_observation', 'cycle' => 'annual', 'status' => 'submitted',
            'assessed_at' => now()->subDay(), 'assessor_user_id' => $employeeId, 'hod_verification' => 'pending',
            'hod_verifier_user_id' => null, 'hod_verified_at' => null,
            'finalized_at' => null, 'finalized_by_user_id' => null,
        ]);
        $assessment->update(['status' => 'pending_hod_verification']);
        $assessment->update(['hod_verification' => 'verified', 'hod_verifier_user_id' => $reviewerId, 'hod_verified_at' => now()]);
        $assessment->update(['status' => 'finalized', 'finalized_at' => now(), 'finalized_by_user_id' => $reviewerId]);

        EmployeeSkillScore::query()->create([
            'tenant_id' => $tenantId, 'company_entity_id' => $companyId, 'employee_entity_id' => $employeeId,
            'skill_id' => $skillId, 'requirement_reference' => $reference, 'requirement_version' => 3,
            'required_level' => $required, 'gap' => $required - $assessed, 'criticality' => 'critical',
            'assessed_at' => now()->subDay(),
            'source_assessment_id' => $assessment->id, 'current_level' => $assessed,
        ]);

        return $assessment;
    });
}

function hodPlanningUser(int $companyId, ?Employee $employee, string $role): User
{
    $user = User::factory()->create(['company_id' => $companyId, 'employee_id' => $employee?->getKey()]);
    setupAuthzRoles();
    PrincipalRole::query()->create([
        'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $role)->sole()->id,
    ]);

    if ($employee !== null) {
        EmployeePortalAccess::query()->create([
            'employee_id' => $employee->id, 'user_id' => $user->id,
            'display_name' => $employee->displayName(), 'status' => EmployeePortalAccess::STATUS_ACTIVE,
        ]);
        SkillActorBinding::query()->create([
            'tenant_id' => $user->tenant_id, 'company_entity_id' => $companyId,
            'platform_user_id' => $user->id, 'employee_entity_id' => $employee->id,
            'user_entity_id' => $user->id, 'confirmed_by_user_id' => $user->id,
            'review_reference' => 'hod-planning-fixture', 'confirmed_at' => now(),
        ]);
    }

    return $user;
}

function hodPlanningOpenNeed(int $tenantId, int $companyId, Employee $employee, PeopleReferenceEntry $unit): void
{
    $creator = User::factory()->create(['company_id' => $companyId]);
    PrincipalCapability::query()->create([
        'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $creator->id, 'capability_key' => TrainingRequestStore::SUBMIT, 'is_allowed' => true,
    ]);

    app(TrainingRequestStore::class)->create($creator, $companyId, new TrainingRequestDraft(
        requestor: new WorkforceSubject(
            $tenantId, $companyId, WorkforceResourceType::Employee, (string) $employee->id),
        department: new WorkforceSubject(
            $tenantId, $companyId, WorkforceResourceType::OrganizationUnit, (string) $unit->id),
        needSource: TrainingNeedSource::PerformanceImprovement,
        need: 'Hod open need for '.$employee->displayName(),
        learningObjective: 'Close the measured gap.',
        expectedResult: 'Assessed level meets requirement.',
        priority: TrainingPriority::High,
        skillGapAssessmentId: null,
        requirementVersion: null,
    ));
}

test('the hod planning route requires the assessment view capability', function (): void {
    $fixture = hodPlanningFixture();
    $hodUser = hodPlanningUser((int) $fixture['company']->id, $fixture['hod'], 'people_hod');

    $this->actingAs($hodUser)
        ->get(route('people.skill.planning.index'))
        ->assertOk();

    $stranger = User::factory()->create();
    $this->actingAs($stranger)
        ->get(route('people.skill.planning.index'))
        ->assertForbidden();
});

test('an hod sees only the assigned departments with their gaps and needs', function (): void {
    $fixture = hodPlanningFixture();
    $companyId = (int) $fixture['company']->id;
    hodPlanningOpenNeed((int) $fixture['tenant']->id, $companyId, $fixture['a1'], $fixture['unitA']);
    $hodUser = hodPlanningUser($companyId, $fixture['hod'], 'people_hod');

    Livewire::actingAs($hodUser)
        ->test(Index::class)
        ->assertSee($fixture['unitA']->name)
        ->assertSee('hod.alpha')
        ->assertSee('Hod open need')
        ->assertDontSee($fixture['unitB']->name)
        ->assertDontSee('hod.beta');
});

test('a viewer without hod scope sees no departments', function (): void {
    $fixture = hodPlanningFixture();
    $user = hodPlanningUser((int) $fixture['company']->id, $fixture['a1'], 'people_employee');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee(__('No departments'))
        ->assertDontSee($fixture['unitA']->name);
});

test('a training request draft without the submit capability is refused', function (): void {
    $fixture = hodPlanningFixture();
    $hodUser = hodPlanningUser((int) $fixture['company']->id, $fixture['hod'], 'people_hod');

    Livewire::actingAs($hodUser)
        ->test(Index::class)
        ->set('reqNeed.'.(string) $fixture['a1']->id, 'Hod draft need')
        ->set('reqObjective.'.(string) $fixture['a1']->id, 'Hod draft objective')
        ->set('reqResult.'.(string) $fixture['a1']->id, 'Hod draft result')
        ->call('draftTrainingRequest', (string) $fixture['a1']->id)
        ->assertForbidden();
});

test('a development action proposal without the manage capability is refused', function (): void {
    $fixture = hodPlanningFixture();
    $user = hodPlanningUser((int) $fixture['company']->id, $fixture['a1'], 'people_employee');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('daObjective.'.(string) $fixture['gapA']->id, 'Hod action objective')
        ->set('daIntervention.'.(string) $fixture['gapA']->id, 'Hod action intervention')
        ->set('daEvidence.'.(string) $fixture['gapA']->id, 'Hod action evidence')
        ->set('daTrainer.'.(string) $fixture['gapA']->id, (string) $fixture['hod']->id)
        ->call('proposeDevelopmentAction', (string) $fixture['gapA']->id)
        ->assertForbidden();
});

test('an hod proposes a development action for an in-scope gap', function (): void {
    $fixture = hodPlanningFixture();
    $hodUser = hodPlanningUser((int) $fixture['company']->id, $fixture['hod'], 'people_hod');

    Livewire::actingAs($hodUser)
        ->test(Index::class)
        ->set('daObjective.'.(string) $fixture['gapA']->id, 'Hod action objective')
        ->set('daIntervention.'.(string) $fixture['gapA']->id, 'Hod action intervention')
        ->set('daEvidence.'.(string) $fixture['gapA']->id, 'Hod action evidence')
        ->set('daTrainer.'.(string) $fixture['gapA']->id, (string) $fixture['hod']->id)
        ->set('daHr.'.(string) $fixture['gapA']->id, (string) $fixture['a2']->id)
        ->call('proposeDevelopmentAction', (string) $fixture['gapA']->id)
        ->assertSee(__('proposed'));

    $action = DevelopmentAction::query()
        ->forCompany((int) $fixture['tenant']->id, (int) $fixture['company']->id)
        ->where('employee_entity_id', $fixture['a1']->id)->sole();
    expect((int) $action->owner_employee_entity_id)->toBe((int) $fixture['hod']->id)
        ->and((int) $action->hr_coordinator_employee_entity_id)->toBe((int) $fixture['a2']->id);
});

test('a development action proposal without an hr coordinator is refused', function (): void {
    $fixture = hodPlanningFixture();
    $hodUser = hodPlanningUser((int) $fixture['company']->id, $fixture['hod'], 'people_hod');

    Livewire::actingAs($hodUser)
        ->test(Index::class)
        ->set('daObjective.'.(string) $fixture['gapA']->id, 'Hod action objective')
        ->set('daIntervention.'.(string) $fixture['gapA']->id, 'Hod action intervention')
        ->set('daEvidence.'.(string) $fixture['gapA']->id, 'Hod action evidence')
        ->set('daTrainer.'.(string) $fixture['gapA']->id, (string) $fixture['hod']->id)
        ->call('proposeDevelopmentAction', (string) $fixture['gapA']->id)
        ->assertSee(__('HR coordinator'));

    expect(DevelopmentAction::query()
        ->forCompany((int) $fixture['tenant']->id, (int) $fixture['company']->id)
        ->where('employee_entity_id', $fixture['a1']->id)->count())->toBe(0);
});

test('an hod with the submit capability drafts a training request', function (): void {
    $fixture = hodPlanningFixture();
    $companyId = (int) $fixture['company']->id;
    $hodUser = hodPlanningUser($companyId, $fixture['hod'], 'people_hod');
    PrincipalCapability::query()->create([
        'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $hodUser->id, 'capability_key' => TrainingRequestStore::SUBMIT, 'is_allowed' => true,
    ]);

    Livewire::actingAs($hodUser)
        ->test(Index::class)
        ->set('reqNeed.'.(string) $fixture['a1']->id, 'Hod positive need')
        ->set('reqObjective.'.(string) $fixture['a1']->id, 'Hod positive objective')
        ->set('reqResult.'.(string) $fixture['a1']->id, 'Hod positive result')
        ->call('draftTrainingRequest', (string) $fixture['a1']->id)
        ->assertSee(__('drafted'));

    expect(TrainingRequest::query()
        ->forCompany((int) $fixture['tenant']->id, $companyId)
        ->where('need', 'Hod positive need')->count())->toBe(1);
});
