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
use App\Domains\People\Skills\Data\RequirementItemDraft;
use App\Domains\People\Skills\Data\RequirementProfileDraft;
use App\Domains\People\Skills\Data\RequirementSelectorDraft;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\CriticalClassification;
use App\Domains\People\Skills\Enums\DevelopmentActionClosure;
use App\Domains\People\Skills\Enums\DevelopmentActionStatus;
use App\Domains\People\Skills\Enums\DevelopmentActionType;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Enums\RequirementProfileStatus;
use App\Domains\People\Skills\Enums\SelectorType;
use App\Domains\People\Skills\Enums\SkillScope;
use App\Domains\People\Skills\Models\DevelopmentAction;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\RequirementProfile;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\RequirementProfileStore;
use App\Domains\People\Skills\Services\SkillAudienceAssignmentStore;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Training\Enums\PilotSignoffRole;
use App\Domains\People\Training\Exceptions\InvalidPilotSignoffException;
use App\Domains\People\Training\Livewire\Migration\Index;
use App\Domains\People\Training\Models\TrainingPilotSignoff;
use App\Domains\People\Training\Services\DepartmentPilotReadiness;
use App\Domains\People\Training\Services\PilotSignoffStore;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * 0015-c: department pilot readiness and HOD/HR sign-off. Helpers are
 * prefixed pilotRd so they cannot collide with other Training Pest files.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

function pilotRdUser(Company $company, string $roleCode, string $name, ?Employee $employee = null): User
{
    $user = User::factory()->create([
        'company_id' => $company->id,
        'name' => $name,
        'employee_id' => $employee?->id,
    ]);
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->sole()->id,
    ]);

    return $user;
}

/**
 * One company side with a headed Core department, an organisation unit, HR,
 * the unit's HOD user, a non-head HOD peer, and staff in the unit.
 *
 * @return array{
 *   tenantId: int,
 *   company: Company,
 *   companyId: int,
 *   unit: PeopleReferenceEntry,
 *   siblingUnit: PeopleReferenceEntry,
 *   department: Department,
 *   hr: User,
 *   hod: User,
 *   peerHod: User,
 *   head: Employee,
 *   staff: Employee,
 *   siblingStaff: Employee,
 *   skill: Skill
 * }
 */
function pilotRdSide(int $tenantId, Company $company, string $label): array
{
    $unit = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id,
        'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'pilot-'.Str::lower(Str::random(8)),
        'name' => $label.' Ops',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $siblingUnit = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id,
        'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'pilot-sib-'.Str::lower(Str::random(8)),
        'name' => $label.' Sibling',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $type = DepartmentType::query()->create([
        'code' => 'pilot-'.Str::lower(Str::random(8)),
        'name' => $label.' Type',
        'category' => 'operational',
        'is_active' => true,
    ]);
    $department = Department::query()->create([
        'company_id' => $company->id,
        'department_type_id' => $type->id,
        'status' => 'active',
    ]);
    $head = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'full_name' => $label.' Head',
        'status' => 'active',
    ]);
    $department->update(['head_id' => $head->id]);
    $staff = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'full_name' => $label.' Staff',
        'status' => 'active',
    ]);
    $siblingStaff = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'full_name' => $label.' Sibling Staff',
        'status' => 'active',
    ]);
    EmployeeWorkProfile::query()->create(['employee_id' => $head->id, 'organization_unit_id' => $unit->id]);
    EmployeeWorkProfile::query()->create(['employee_id' => $staff->id, 'organization_unit_id' => $unit->id]);
    EmployeeWorkProfile::query()->create(['employee_id' => $siblingStaff->id, 'organization_unit_id' => $siblingUnit->id]);

    $hod = pilotRdUser($company, 'people_hod', $label.' HOD', $head);
    $peerHod = pilotRdUser($company, 'people_hod', $label.' Peer HOD');
    $hr = pilotRdUser($company, 'people_hr', $label.' HR');

    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory((int) $company->id, 'pilot-'.Str::lower(Str::random(6)), $label.' Safety');
    $skill = $catalog->defineSkill((int) $company->id, new SkillDraft(
        code: 'pilot-'.Str::lower(Str::random(6)),
        name: $label.' Skill',
        definition: 'Pilot skill',
        categoryId: (int) $category->id,
        scope: SkillScope::Shared,
        criticalClassification: CriticalClassification::Safety,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));

    return [
        'tenantId' => $tenantId,
        'company' => $company,
        'companyId' => (int) $company->id,
        'unit' => $unit,
        'siblingUnit' => $siblingUnit,
        'department' => $department,
        'hr' => $hr,
        'hod' => $hod,
        'peerHod' => $peerHod,
        'head' => $head,
        'staff' => $staff,
        'siblingStaff' => $siblingStaff,
        'skill' => $skill,
    ];
}

/** @return array{tenantId: int, alpha: array, beta: array} */
function pilotRdFixture(string $label = 'PilotRd'): array
{
    $tenant = createTenant(['name' => $label.' Tenant']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();
    $alpha = Company::factory()->create(['tenant_id' => $tenantId, 'name' => $label.' Alpha', 'status' => 'active']);
    $beta = Company::factory()->create(['tenant_id' => $tenantId, 'name' => $label.' Beta', 'status' => 'active']);

    return [
        'tenantId' => $tenantId,
        'alpha' => pilotRdSide($tenantId, $alpha, $label.' A'),
        'beta' => pilotRdSide($tenantId, $beta, $label.' B'),
    ];
}

function pilotRdDraftProfile(array $s, bool $forSibling = false): RequirementProfile
{
    $unit = $forSibling ? $s['siblingUnit'] : $s['unit'];

    return app(RequirementProfileStore::class)->draft($s['companyId'], new RequirementProfileDraft(
        code: 'pilot.'.Str::lower(Str::random(8)),
        name: 'Pilot profile',
        selectors: [new RequirementSelectorDraft(SelectorType::Department, null, (int) $unit->id)],
        items: [new RequirementItemDraft(
            skillId: (int) $s['skill']->id,
            sequence: 1,
            requiredLevel: 3,
            criticality: RequirementCriticality::Critical,
            weightPercent: 100.0,
        )],
    ));
}

function pilotRdPublish(array $s, RequirementProfile $profile): RequirementProfile
{
    return app(RequirementProfileStore::class)->publish($s['companyId'], (int) $profile->id);
}

function pilotRdRow(array $rows, string $check): array
{
    $row = collect($rows)->firstWhere('check', $check);
    expect($row)->not->toBeNull();

    return $row;
}

function pilotRdAssignAssessor(array $s, User $assessor, Employee $subject): void
{
    app(SkillAudienceAssignmentStore::class)->assignAssessor(
        $s['hr'],
        $assessor,
        $s['companyId'],
        (int) $subject->id,
        'pilot-assessor-'.Str::lower(Str::random(6)),
    );
}

function pilotRdScore(array $s, Employee $employee, int $level): void
{
    $assessment = SkillAssessment::query()->create([
        'tenant_id' => $s['tenantId'],
        'company_entity_id' => $s['companyId'],
        'employee_entity_id' => $employee->id,
        'skill_id' => $s['skill']->id,
        'requirement_reference' => 'pilot',
        'requirement_version' => 1,
        'required_level' => 3,
        'assessed_level' => $level,
        'gap' => max(3 - $level, 0),
        'criticality' => RequirementCriticality::Critical,
        'method' => 'direct_observation',
        'cycle' => 'annual',
        'status' => 'draft',
        'assessed_at' => now(),
    ]);
    EmployeeSkillScore::query()->create([
        'tenant_id' => $s['tenantId'],
        'company_entity_id' => $s['companyId'],
        'employee_entity_id' => $employee->id,
        'skill_id' => $s['skill']->id,
        'source_assessment_id' => $assessment->id,
        'requirement_reference' => 'pilot',
        'requirement_version' => 1,
        'required_level' => 3,
        'current_level' => $level,
        'gap' => max(3 - $level, 0),
        'criticality' => RequirementCriticality::Critical,
        'mandatory_gate' => false,
        'assessed_at' => now(),
    ]);
}

function pilotRdOpenAction(array $s, Employee $employee, int $ownerEmployeeId): DevelopmentAction
{
    return DevelopmentAction::query()->create([
        'tenant_id' => $s['tenantId'],
        'company_entity_id' => $s['companyId'],
        'action_key' => (string) Str::uuid(),
        'employee_entity_id' => $employee->id,
        'skill_id' => $s['skill']->id,
        'employee_name_snapshot' => $employee->full_name,
        'starting_level' => 1,
        'target_level' => 3,
        'gap_at_start' => 2,
        'criticality' => RequirementCriticality::Critical,
        'mandatory_gate' => false,
        'priority_score' => 1,
        'priority_explanation' => 'pilot',
        'action_type' => DevelopmentActionType::ClassroomTraining,
        'objective' => 'Close the gap',
        'intervention' => 'Classroom course',
        'expected_evidence' => 'Certificate',
        'status' => DevelopmentActionStatus::InProgress,
        'closure_status' => DevelopmentActionClosure::Open,
        'owner_employee_entity_id' => $ownerEmployeeId,
        'hr_coordinator_employee_entity_id' => $ownerEmployeeId,
        'start_date' => now()->toDateString(),
        'due_date' => now()->addMonth()->toDateString(),
    ]);
}

function pilotRdMakeGreen(array $s): array
{
    $profile = pilotRdPublish($s, pilotRdDraftProfile($s));
    $assessor = pilotRdUser($s['company'], 'people_employee', 'Assessor', $s['head']);
    PrincipalRole::query()->create([
        'company_id' => $s['companyId'],
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $assessor->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_assessor')->sole()->id,
    ]);
    pilotRdAssignAssessor($s, $assessor, $s['staff']);
    // Meet the required level so the gaps row is green (vacuous).
    pilotRdScore($s, $s['head'], 3);
    pilotRdScore($s, $s['staff'], 3);
    $rows = app(DepartmentPilotReadiness::class)->rows($s['companyId'], (int) $s['unit']->id);
    expect(collect($rows)->every(fn (array $row): bool => $row['status'] === 'green'))->toBeTrue();

    return ['profile' => $profile, 'rows' => $rows, 'assessor' => $assessor];
}

test('a unit with a draft profile reports the profile check red; publishing it turns it green', function (): void {
    $f = pilotRdFixture();
    $a = $f['alpha'];
    $readiness = app(DepartmentPilotReadiness::class);

    $draft = pilotRdDraftProfile($a);
    expect($draft->status)->toBe(RequirementProfileStatus::Draft);

    $before = pilotRdRow($readiness->rows($a['companyId'], (int) $a['unit']->id), 'published_profile');
    expect($before['status'])->toBe('red')->and($before['count'])->toBe(0);

    pilotRdPublish($a, $draft);

    $after = pilotRdRow($readiness->rows($a['companyId'], (int) $a['unit']->id), 'published_profile');
    expect($after['status'])->toBe('green')->and($after['count'])->toBe(1)->and($after['ids'])->toContain((int) $draft->id);
});

test('a required skill with no active assessor is red; a sibling company assessor does not turn it green', function (): void {
    $f = pilotRdFixture();
    $a = $f['alpha'];
    $b = $f['beta'];
    pilotRdPublish($a, pilotRdDraftProfile($a));
    $readiness = app(DepartmentPilotReadiness::class);

    $red = pilotRdRow($readiness->rows($a['companyId'], (int) $a['unit']->id), 'assessors');
    expect($red['status'])->toBe('red')->and($red['count'])->toBeGreaterThan(0);

    $betaAssessor = pilotRdUser($b['company'], 'people_employee', 'Beta Assessor', $b['head']);
    PrincipalRole::query()->create([
        'company_id' => $b['companyId'],
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $betaAssessor->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_assessor')->sole()->id,
    ]);
    pilotRdAssignAssessor($b, $betaAssessor, $b['staff']);

    $stillRed = pilotRdRow($readiness->rows($a['companyId'], (int) $a['unit']->id), 'assessors');
    expect($stillRed['status'])->toBe('red');

    $alphaAssessor = pilotRdUser($a['company'], 'people_employee', 'Alpha Assessor', $a['head']);
    PrincipalRole::query()->create([
        'company_id' => $a['companyId'],
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $alphaAssessor->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_assessor')->sole()->id,
    ]);
    pilotRdAssignAssessor($a, $alphaAssessor, $a['staff']);

    $green = pilotRdRow($readiness->rows($a['companyId'], (int) $a['unit']->id), 'assessors');
    expect($green['status'])->toBe('green')->and($green['count'])->toBe(0);
});

test('gap count equals the employees below required level on that unit only', function (): void {
    $f = pilotRdFixture();
    $a = $f['alpha'];
    pilotRdPublish($a, pilotRdDraftProfile($a));
    // Head meets the bar; staff and sibling staff are below. Only the unit's staff counts.
    pilotRdScore($a, $a['head'], 3);
    pilotRdScore($a, $a['staff'], 1);
    pilotRdScore($a, $a['siblingStaff'], 1);

    $gaps = pilotRdRow(app(DepartmentPilotReadiness::class)->rows($a['companyId'], (int) $a['unit']->id), 'gaps');
    expect($gaps['count'])->toBe(1)
        ->and($gaps['ids'])->toContain((int) $a['staff']->id)
        ->and($gaps['ids'])->not->toContain((int) $a['siblingStaff']->id)
        ->and($gaps['with_open_action'])->toBe(0);

    pilotRdOpenAction($a, $a['staff'], (int) $a['head']->id);
    $withAction = pilotRdRow(app(DepartmentPilotReadiness::class)->rows($a['companyId'], (int) $a['unit']->id), 'gaps');
    expect($withAction['with_open_action'])->toBe(1);
});

test('HOD sign-off is refused while any check is red and by a user who is not that unit head', function (): void {
    $f = pilotRdFixture();
    $a = $f['alpha'];
    $store = app(PilotSignoffStore::class);
    $before = TrainingPilotSignoff::query()->forCompany($a['tenantId'], $a['companyId'])->count();

    // Draft profile keeps published_profile red.
    pilotRdDraftProfile($a);

    expect(fn () => $store->signAsHod($a['hod'], $a['companyId'], (int) $a['unit']->id))
        ->toThrow(InvalidPilotSignoffException::class);
    expect(TrainingPilotSignoff::query()->forCompany($a['tenantId'], $a['companyId'])->count())->toBe($before);

    pilotRdMakeGreen($a);

    expect(fn () => $store->signAsHod($a['peerHod'], $a['companyId'], (int) $a['unit']->id))
        ->toThrow(InvalidPilotSignoffException::class);
    expect(TrainingPilotSignoff::query()->forCompany($a['tenantId'], $a['companyId'])->count())->toBe($before);
});

test('HR sign-off is refused before the HOD sign-off and accepted after with the readiness snapshot', function (): void {
    $f = pilotRdFixture();
    $a = $f['alpha'];
    $store = app(PilotSignoffStore::class);
    $green = pilotRdMakeGreen($a);

    expect(fn () => $store->signAsHr($a['hr'], $a['companyId'], (int) $a['unit']->id))
        ->toThrow(InvalidPilotSignoffException::class);

    $hod = $store->signAsHod($a['hod'], $a['companyId'], (int) $a['unit']->id, 'HOD ready');
    expect($hod->role)->toBe(PilotSignoffRole::Hod)
        ->and($hod->readiness_snapshot)->toEqual($green['rows']);

    $hr = $store->signAsHr($a['hr'], $a['companyId'], (int) $a['unit']->id, 'HR ready');
    $snapshot = app(DepartmentPilotReadiness::class)->rows($a['companyId'], (int) $a['unit']->id);
    expect($hr->role)->toBe(PilotSignoffRole::Hr)
        ->and($hr->readiness_snapshot)->toEqual($snapshot)
        ->and($hr->signed_by)->toBe((int) $a['hr']->id);
});

test('a second HOD sign-off for the same unit is refused and the ledger keeps exactly one', function (): void {
    $f = pilotRdFixture();
    $a = $f['alpha'];
    $store = app(PilotSignoffStore::class);
    pilotRdMakeGreen($a);

    $store->signAsHod($a['hod'], $a['companyId'], (int) $a['unit']->id);
    expect(fn () => $store->signAsHod($a['hod'], $a['companyId'], (int) $a['unit']->id))
        ->toThrow(InvalidPilotSignoffException::class);

    expect(TrainingPilotSignoff::query()->forCompany($a['tenantId'], $a['companyId'])
        ->where('organization_unit_entity_id', $a['unit']->id)
        ->where('role', PilotSignoffRole::Hod->value)
        ->count())->toBe(1);
});

test('a sibling tenant\'s sign-offs and readiness are never visible', function (): void {
    $f = pilotRdFixture('PilotIso');
    $a = $f['alpha'];
    pilotRdMakeGreen($a);
    app(PilotSignoffStore::class)->signAsHod($a['hod'], $a['companyId'], (int) $a['unit']->id);

    $other = createTenant(['name' => 'Other Pilot Tenant']);
    app(TenantContext::class)->set((int) $other->id);
    setupAuthzRoles();
    $foreign = Company::factory()->create(['tenant_id' => $other->id, 'name' => 'Foreign Co', 'status' => 'active']);
    $foreignSide = pilotRdSide((int) $other->id, $foreign, 'Foreign');

    expect(app(DepartmentPilotReadiness::class)->rows($foreignSide['companyId'], (int) $a['unit']->id))
        ->toBeArray();
    expect(TrainingPilotSignoff::query()->forCompany((int) $other->id, $foreignSide['companyId'])->count())->toBe(0);

    // Alpha's rows stay invisible under the foreign tenant+company pin.
    expect(TrainingPilotSignoff::query()->forCompany((int) $other->id, $a['companyId'])->count())->toBe(0);
});

test('the migration page shows a captioned readiness table and HOD/HR sign actions one unit at a time', function (): void {
    $f = pilotRdFixture('PilotPage');
    $a = $f['alpha'];
    pilotRdMakeGreen($a);

    Livewire::actingAs($a['hod'])
        ->test(Index::class)
        ->call('selectUnit', (int) $a['unit']->id)
        ->assertSee(__('Department pilot readiness'))
        ->call('signAsHod')
        ->assertHasNoErrors();

    expect(TrainingPilotSignoff::query()->forCompany($a['tenantId'], $a['companyId'])
        ->where('role', PilotSignoffRole::Hod->value)->count())->toBe(1);

    Livewire::actingAs($a['hr'])
        ->test(Index::class)
        ->call('selectUnit', (int) $a['unit']->id)
        ->call('signAsHr')
        ->assertHasNoErrors();

    expect(TrainingPilotSignoff::query()->forCompany($a['tenantId'], $a['companyId'])
        ->where('role', PilotSignoffRole::Hr->value)->count())->toBe(1);
});
