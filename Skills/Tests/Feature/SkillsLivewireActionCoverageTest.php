<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
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
use App\Domains\People\Skills\Data\DevelopmentActionDraft;
use App\Domains\People\Skills\Data\ProficiencyLevelDraft;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentCycle;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\AssessmentResultBand;
use App\Domains\People\Skills\Enums\AssessmentStatus;
use App\Domains\People\Skills\Enums\CriticalClassification;
use App\Domains\People\Skills\Enums\DevelopmentActionClosure;
use App\Domains\People\Skills\Enums\DevelopmentActionStatus;
use App\Domains\People\Skills\Enums\DevelopmentActionType;
use App\Domains\People\Skills\Enums\HodVerification;
use App\Domains\People\Skills\Enums\ProficiencyScaleStatus;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Enums\SkillScope;
use App\Domains\People\Skills\Livewire\Assessment\Matrix;
use App\Domains\People\Skills\Livewire\Catalog\Index as CatalogIndex;
use App\Domains\People\Skills\Livewire\DevelopmentAction\Index as DevelopmentActionIndex;
use App\Domains\People\Skills\Models\DevelopmentAction;
use App\Domains\People\Skills\Models\DevelopmentActionAuditEvent;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\ProficiencyScale;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Models\SkillCategory;
use App\Domains\People\Skills\Services\AssessmentWorkflowContext;
use App\Domains\People\Skills\Services\DevelopmentActionStore;
use App\Domains\People\Skills\Services\ProficiencyScaleStore;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Coverage for Skills Livewire actions that no other test calls
|--------------------------------------------------------------------------
|
| Native workforce fixture: a platform tenant, company and Employee rows,
| the same shape DevelopmentActionStoreTest builds. Every helper is local
| to this file so it runs alone. Only the tests/Pest.php bootstrap helpers
| (createTenantWithCompany, setupAuthzRoles) are shared.
*/

beforeEach(function (): void {
    $this->withoutVite();
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/** @return array{tenant: int, company: int, hr: User} */
function coverageHrTenant(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name.' Tenant'], ['name' => $name.' Company', 'status' => 'active']);
    app(TenantContext::class)->set((int) $tenant->id);

    setupAuthzRoles();
    $hr = User::factory()->create(['company_id' => $company->id]);
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $hr->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_hr')->valueOrFail('id'),
    ]);

    return ['tenant' => (int) $tenant->id, 'company' => (int) $company->id, 'hr' => $hr];
}

/**
 * A same-company user who may open the screen (view capability and an HR
 * audience) but holds no manage capability, so every mutating action denies.
 */
function coverageViewer(int $companyId, string $viewCapability): User
{
    $viewer = User::factory()->create(['company_id' => $companyId]);

    foreach ([$viewCapability, 'people.skill.hr.view'] as $capability) {
        PrincipalCapability::query()->create([
            'company_id' => $companyId,
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $viewer->id,
            'capability_key' => $capability,
            'is_allowed' => true,
        ]);
    }

    return $viewer;
}

/** @return list<int> employee ids, all visible to HR through the native workforce directory */
function coverageEmployees(int $companyId, int $count): array
{
    $organization = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId,
        'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'operations-'.Str::lower(Str::random(8)),
        'name' => 'Operations',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $position = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId,
        'type' => PeopleReferenceEntry::TYPE_JOB_TITLE,
        'code' => 'technician-'.Str::lower(Str::random(8)),
        'name' => 'Technician',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $departmentType = DepartmentType::query()->create([
        'code' => 'coverage-'.Str::lower(Str::random(8)),
        'name' => 'Coverage',
        'category' => 'operational',
        'is_active' => true,
    ]);
    $department = Department::query()->create([
        'company_id' => $companyId,
        'department_type_id' => $departmentType->id,
        'status' => 'active',
    ]);

    $employees = [];
    foreach (range(1, $count) as $index) {
        $employee = Employee::factory()->create([
            'company_id' => $companyId,
            'department_id' => $department->id,
            'full_name' => "Coverage Person {$index}",
            'short_name' => null,
            'designation' => 'Technician',
            'employee_type' => 'full_time',
            'status' => 'active',
        ]);
        EmployeeWorkProfile::query()->create([
            'employee_id' => $employee->id,
            'organization_unit_id' => $organization->id,
            'job_title_id' => $position->id,
        ]);
        $employees[] = (int) $employee->id;
    }

    return $employees;
}

/** @return array{category: SkillCategory, skill: Skill} */
function coverageCatalog(int $companyId, string $code = 'safety'): array
{
    $store = app(SkillCatalogStore::class);
    $category = $store->defineCategory($companyId, $code, ucfirst($code));
    $skill = $store->defineSkill($companyId, new SkillDraft(
        code: $code.'.permit',
        name: ucfirst($code).' Permit',
        definition: 'Works safely under permit.',
        categoryId: (int) $category->id,
        scope: SkillScope::Shared,
        criticalClassification: CriticalClassification::Safety,
        evidenceGuide: 'Observed permit execution.',
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
        defaultReassessmentMonths: 12,
    ));

    return ['category' => $category, 'skill' => $skill];
}

function coverageDraftScale(int $companyId, string $code = 'probe'): ProficiencyScale
{
    return app(ProficiencyScaleStore::class)->draft($companyId, $code, ucfirst($code), [
        new ProficiencyLevelDraft(0, 'None', 'No demonstrated capability.', 'None.'),
        new ProficiencyLevelDraft(1, 'Basic', 'Works with supervision.', 'Supervised.'),
        new ProficiencyLevelDraft(2, 'Full', 'Works unsupervised.', 'Authorised.'),
    ]);
}

/** @param list<int> $employees */
function coverageProposedAction(int $companyId, array $employees, Skill $skill, User $actor): DevelopmentAction
{
    return app(DevelopmentActionStore::class)->proposeManual($companyId, new DevelopmentActionDraft(
        employeeEntityId: $employees[0],
        type: DevelopmentActionType::Coaching,
        objective: 'Reach permit level four safely.',
        intervention: 'Four supervised permit cycles with feedback.',
        expectedEvidence: 'Signed observation checklist for four cycles.',
        ownerEmployeeEntityId: $employees[1],
        hrCoordinatorEmployeeEntityId: $employees[2],
        startDate: now(),
        dueDate: now()->addDays(10),
        trainerEmployeeEntityId: $employees[3],
        skillId: (int) $skill->id,
        startingLevel: 1,
        targetLevel: 4,
        criticality: RequirementCriticality::Critical,
        mandatoryGate: true,
        manualReason: 'Coverage fixture.',
    ), (int) $actor->id);
}

function coverageFinalizedAssessment(int $tenantId, int $companyId, int $employeeId, Skill $skill): SkillAssessment
{
    $assessedAt = now()->subDay();
    $assessment = AssessmentWorkflowContext::runStoreMutation(static fn (): SkillAssessment => SkillAssessment::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId,
        'employee_entity_id' => $employeeId, 'skill_id' => $skill->id,
        'requirement_reference' => 'coverage.safety', 'requirement_version' => 1, 'required_level' => 4,
        'criticality' => RequirementCriticality::Critical, 'weight_percent' => 100,
        'mandatory_gate' => true, 'assessed_level' => 1, 'gap' => 3,
        'weighted_gap' => 300, 'priority_score' => 900,
        'result_band' => AssessmentResultBand::fromGap(3, 1, 4),
        'method' => AssessmentMethod::DirectObservation, 'cycle' => AssessmentCycle::Annual,
        'status' => AssessmentStatus::Submitted, 'evidence' => 'Observed work sample.', 'assessed_at' => $assessedAt,
        'assessor_user_id' => 9, 'hod_verification' => HodVerification::Pending,
    ]));

    foreach ([
        ['status' => AssessmentStatus::PendingHodVerification],
        ['hod_verification' => HodVerification::Verified, 'hod_verifier_user_id' => 10, 'hod_verified_at' => $assessedAt],
        ['status' => AssessmentStatus::Finalized, 'finalized_at' => $assessedAt, 'finalized_by_user_id' => 10],
    ] as $attributes) {
        $assessment->fill($attributes);
        AssessmentWorkflowContext::runStoreMutation(static function () use ($assessment): void {
            $assessment->save();
        });
    }

    EmployeeSkillScore::query()->forCompany($tenantId, $companyId)->updateOrCreate(
        ['tenant_id' => $tenantId, 'employee_entity_id' => $employeeId, 'skill_id' => $skill->id],
        [
            'company_entity_id' => $companyId, 'source_assessment_id' => $assessment->id,
            'requirement_reference' => 'coverage.safety', 'requirement_version' => 1, 'required_level' => 4,
            'current_level' => 1, 'gap' => 3, 'mandatory_gate' => true,
            'criticality' => RequirementCriticality::Critical, 'assessed_at' => $assessedAt,
        ],
    );

    return $assessment;
}

// ─── Catalog: skills and categories ───────────────────────────────────────────

test('cancelSkill discards the open skill form without persisting anything', function (): void {
    ['tenant' => $tenantId, 'company' => $companyId, 'hr' => $hr] = coverageHrTenant('Cancel Skill');
    coverageCatalog($companyId);

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('startSkill')
        ->set('skillForm.code', 'abandoned.skill')
        ->set('skillForm.name', 'Abandoned')
        ->call('cancelSkill')
        ->assertSet('editingSkillId', null)
        ->assertSet('skillForm', []);

    expect(Skill::query()->forCompany($tenantId, $companyId)->where('code', 'abandoned.skill')->exists())->toBeFalse();
});

test('toggleSkillActive flips a skill and refuses a foreign skill, a dead category, and non-managers', function (): void {
    ['company' => $companyId, 'hr' => $hr] = coverageHrTenant('Toggle Skill');
    ['category' => $category, 'skill' => $skill] = coverageCatalog($companyId);
    $otherCompany = Company::factory()->create(['tenant_id' => app(TenantContext::class)->requireTenantId(), 'status' => 'active']);
    ['skill' => $foreign] = coverageCatalog((int) $otherCompany->id, 'foreign');

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('toggleSkillActive', $skill->id)
        ->assertHasNoErrors();

    expect($skill->refresh()->active)->toBeFalse();

    // The skill is inactive, so the category may go inactive too; reactivating
    // the skill under a dead category is the store's refusal, surfaced as a form error.
    app(SkillCatalogStore::class)->deactivateCategory($companyId, (int) $category->id);

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('toggleSkillActive', $skill->id)
        ->assertHasErrors(['skills' => 'Reactivate the skill category before reactivating its skills.']);

    expect($skill->refresh()->active)->toBeFalse();

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('toggleSkillActive', $foreign->id)
        ->assertStatus(404);

    Livewire::actingAs(coverageViewer($companyId, 'people.skill.catalog.view'))->test(CatalogIndex::class)
        ->call('toggleSkillActive', $skill->id)
        ->assertForbidden();

    expect($foreign->refresh()->active)->toBeTrue();
});

test('saveCategory defines a category, clears the form, and refuses a duplicate code and non-managers', function (): void {
    ['company' => $companyId, 'hr' => $hr] = coverageHrTenant('Save Category');

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->set('newCategoryCode', 'quality')
        ->set('newCategoryName', 'Quality')
        ->call('saveCategory')
        ->assertHasNoErrors()
        ->assertSet('newCategoryCode', null)
        ->assertSet('newCategoryName', null);

    expect(SkillCategory::query()->where('company_entity_id', $companyId)->where('code', 'quality')->exists())->toBeTrue();

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->set('newCategoryCode', 'quality')
        ->set('newCategoryName', 'Quality Again')
        ->call('saveCategory')
        ->assertHasErrors(['categoryForm' => 'Skill category code [quality] already exists for this company.'])
        ->assertSet('newCategoryCode', 'quality');

    Livewire::actingAs(coverageViewer($companyId, 'people.skill.catalog.view'))->test(CatalogIndex::class)
        ->set('newCategoryCode', 'blocked')
        ->set('newCategoryName', 'Blocked')
        ->call('saveCategory')
        ->assertForbidden();

    expect(SkillCategory::query()->where('company_entity_id', $companyId)->count())->toBe(1);
});

test('renameCategory renames, refuses a blank name, and denies non-managers', function (): void {
    ['company' => $companyId, 'hr' => $hr] = coverageHrTenant('Rename Category');
    ['category' => $category] = coverageCatalog($companyId);

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('renameCategory', $category->id, '  Safety and Health  ')
        ->assertHasNoErrors();

    expect($category->refresh()->name)->toBe('Safety and Health');

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('renameCategory', $category->id, '   ')
        ->assertHasErrors(['categoryForm' => 'A skill category needs a name.']);

    Livewire::actingAs(coverageViewer($companyId, 'people.skill.catalog.view'))->test(CatalogIndex::class)
        ->call('renameCategory', $category->id, 'Hijacked')
        ->assertForbidden();

    expect($category->refresh()->name)->toBe('Safety and Health');
});

test('toggleCategoryActive flips a category and refuses one with active skills, a foreign one, and non-managers', function (): void {
    ['company' => $companyId, 'hr' => $hr] = coverageHrTenant('Toggle Category');
    ['category' => $category, 'skill' => $skill] = coverageCatalog($companyId);
    $otherCompany = Company::factory()->create(['tenant_id' => app(TenantContext::class)->requireTenantId(), 'status' => 'active']);
    ['category' => $foreign] = coverageCatalog((int) $otherCompany->id, 'foreign');

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('toggleCategoryActive', $category->id)
        ->assertHasErrors(['categoryForm' => 'Skill category [safety] still has active skills; deactivate or recategorize them first.']);

    expect($category->refresh()->active)->toBeTrue();

    app(SkillCatalogStore::class)->deactivateSkill($companyId, (int) $skill->id);

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('toggleCategoryActive', $category->id)
        ->assertHasNoErrors()
        ->call('toggleCategoryActive', $category->id)
        ->assertHasNoErrors();

    expect($category->refresh()->active)->toBeTrue();

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('toggleCategoryActive', $foreign->id)
        ->assertStatus(404);

    Livewire::actingAs(coverageViewer($companyId, 'people.skill.catalog.view'))->test(CatalogIndex::class)
        ->call('toggleCategoryActive', $category->id)
        ->assertForbidden();

    expect($foreign->refresh()->active)->toBeTrue();
});

// ─── Catalog: proficiency scales ──────────────────────────────────────────────

test('publishScale publishes a draft, reports a second publish as a failed action, and denies non-managers', function (): void {
    ['company' => $companyId, 'hr' => $hr] = coverageHrTenant('Publish Scale');
    $scale = coverageDraftScale($companyId);

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('publishScale', $scale->id)
        ->assertHasNoErrors()
        ->assertNotDispatched('notify');

    expect($scale->refresh()->status)->toBe(ProficiencyScaleStatus::Published);

    // ProficiencyScaleStateException is a RuntimeException, so the platform's
    // RecoverFromActionFailure hook turns it into an error toast.
    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('publishScale', $scale->id)
        ->assertDispatched('notify', fn (string $event, array $params): bool => ($params['variant'] ?? null) === 'error');

    $draft = coverageDraftScale($companyId, 'second');

    Livewire::actingAs(coverageViewer($companyId, 'people.skill.catalog.view'))->test(CatalogIndex::class)
        ->call('publishScale', $draft->id)
        ->assertForbidden();

    expect($draft->refresh()->status)->toBe(ProficiencyScaleStatus::Draft);
});

test('draftNewScaleVersion opens the next draft of a published scale and denies non-managers', function (): void {
    ['company' => $companyId, 'hr' => $hr] = coverageHrTenant('Draft Scale');
    $scale = coverageDraftScale($companyId);
    app(ProficiencyScaleStore::class)->publish($companyId, (int) $scale->id);

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('draftNewScaleVersion', $scale->id)
        ->assertHasNoErrors()
        ->assertNotDispatched('notify');

    $versions = ProficiencyScale::query()->where('company_entity_id', $companyId)->where('code', 'probe')->orderBy('version')->get();

    expect($versions)->toHaveCount(2)
        ->and($versions->last()->status)->toBe(ProficiencyScaleStatus::Draft)
        ->and($versions->last()->version)->toBeGreaterThan($versions->first()->version)
        ->and($versions->last()->levels()->count())->toBe(3);

    // A second open draft on the same code is refused by the store and
    // surfaces as a failed-action toast, leaving the version count alone.
    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('draftNewScaleVersion', $scale->id)
        ->assertDispatched('notify', fn (string $event, array $params): bool => ($params['variant'] ?? null) === 'error');

    Livewire::actingAs(coverageViewer($companyId, 'people.skill.catalog.view'))->test(CatalogIndex::class)
        ->call('draftNewScaleVersion', $scale->id)
        ->assertForbidden();

    expect(ProficiencyScale::query()->where('company_entity_id', $companyId)->where('code', 'probe')->count())->toBe(2);
});

// ─── Assessment matrix ────────────────────────────────────────────────────────

test('toggleSkill selects and deselects skills, caps the selection at twelve, and denies viewers', function (): void {
    ['company' => $companyId, 'hr' => $hr] = coverageHrTenant('Toggle Matrix Skill');
    coverageEmployees($companyId, 1);
    ['skill' => $skill] = coverageCatalog($companyId);

    $component = Livewire::actingAs($hr)->test(Matrix::class)
        ->call('toggleSkill', $skill->id)
        ->assertSet('selectedSkillIds', [$skill->id])
        ->call('toggleSkill', $skill->id)
        ->assertSet('selectedSkillIds', []);

    $component->set('selectedSkillIds', range(1001, 1012))
        ->call('toggleSkill', $skill->id)
        ->assertCount('selectedSkillIds', 12)
        ->call('toggleSkill', 1001)
        ->assertCount('selectedSkillIds', 11);

    $viewer = User::factory()->create(['company_id' => $companyId]);
    foreach (['people.skill.assessment.view', 'people.skill.employee.view'] as $capability) {
        PrincipalCapability::query()->create([
            'company_id' => $companyId,
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $viewer->id,
            'capability_key' => $capability,
            'is_allowed' => true,
        ]);
    }

    Livewire::actingAs($viewer)->test(Matrix::class)
        ->call('toggleSkill', $skill->id)
        ->assertForbidden();
});

// ─── Development actions ──────────────────────────────────────────────────────

test('toggleAssessment selects and deselects a visible gap and refuses a foreign one and viewers', function (): void {
    ['tenant' => $tenantId, 'company' => $companyId, 'hr' => $hr] = coverageHrTenant('Toggle Assessment');
    $employees = coverageEmployees($companyId, 1);
    ['skill' => $skill] = coverageCatalog($companyId);
    $assessment = coverageFinalizedAssessment($tenantId, $companyId, $employees[0], $skill);

    $otherCompany = Company::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);
    $otherEmployees = coverageEmployees((int) $otherCompany->id, 1);
    ['skill' => $otherSkill] = coverageCatalog((int) $otherCompany->id, 'foreign');
    $foreign = coverageFinalizedAssessment($tenantId, (int) $otherCompany->id, $otherEmployees[0], $otherSkill);

    Livewire::actingAs($hr)->test(DevelopmentActionIndex::class)
        ->call('toggleAssessment', $assessment->id)
        ->assertSet('selectedAssessmentIds', [$assessment->id])
        ->call('toggleAssessment', $assessment->id)
        ->assertSet('selectedAssessmentIds', []);

    // authorizedAssessment() uses firstOrFail, so the miss propagates as a
    // ModelNotFoundException rather than an abort(404) status.
    expect(fn () => Livewire::actingAs($hr)->test(DevelopmentActionIndex::class)->call('toggleAssessment', $foreign->id))
        ->toThrow(ModelNotFoundException::class);

    Livewire::actingAs(coverageViewer($companyId, 'people.skill.development-action.view'))->test(DevelopmentActionIndex::class)
        ->call('toggleAssessment', $assessment->id)
        ->assertForbidden();
});

test('tailor revises a proposal, refuses approved work, and denies viewers', function (): void {
    ['company' => $companyId, 'hr' => $hr] = coverageHrTenant('Tailor');
    $employees = coverageEmployees($companyId, 4);
    ['skill' => $skill] = coverageCatalog($companyId);
    $action = coverageProposedAction($companyId, $employees, $skill, $hr);

    Livewire::actingAs($hr)->test(DevelopmentActionIndex::class)
        ->set('objective', 'Tailored objective.')
        ->set('intervention', 'Tailored intervention.')
        ->set('expectedEvidence', 'Tailored evidence.')
        ->set('ownerEmployeeEntityId', $employees[1])
        ->set('hrCoordinatorEmployeeEntityId', $employees[2])
        ->set('trainerEmployeeEntityId', $employees[3])
        ->call('tailor', $action->id)
        ->assertHasNoErrors();

    expect($action->refresh()->objective)->toBe('Tailored objective.')
        ->and($action->status)->toBe(DevelopmentActionStatus::Proposed);

    app(DevelopmentActionStore::class)->approve($companyId, (int) $action->id, (int) $hr->id);

    Livewire::actingAs($hr)->test(DevelopmentActionIndex::class)
        ->set('objective', 'Too late.')
        ->set('ownerEmployeeEntityId', $employees[1])
        ->set('hrCoordinatorEmployeeEntityId', $employees[2])
        ->call('tailor', $action->id)
        ->assertHasErrors(['actions' => 'Only a proposal can be tailored; approved work requires a lifecycle transition.']);

    Livewire::actingAs(coverageViewer($companyId, 'people.skill.development-action.view'))->test(DevelopmentActionIndex::class)
        ->set('ownerEmployeeEntityId', $employees[1])
        ->set('hrCoordinatorEmployeeEntityId', $employees[2])
        ->call('tailor', $action->id)
        ->assertForbidden();

    expect($action->refresh()->objective)->toBe('Tailored objective.');
});

test('start moves approved work into progress and refuses a proposal, a foreign action, and viewers', function (): void {
    ['tenant' => $tenantId, 'company' => $companyId, 'hr' => $hr] = coverageHrTenant('Start');
    $employees = coverageEmployees($companyId, 4);
    ['skill' => $skill] = coverageCatalog($companyId);
    $action = coverageProposedAction($companyId, $employees, $skill, $hr);

    // A proposal cannot start; the store's refusal is a DomainException the
    // component does not catch here, so it surfaces as a failed-action toast.
    Livewire::actingAs($hr)->test(DevelopmentActionIndex::class)
        ->call('start', $action->id)
        ->assertDispatched('notify', fn (string $event, array $params): bool => ($params['variant'] ?? null) === 'error');

    expect($action->refresh()->status)->toBe(DevelopmentActionStatus::Proposed);

    app(DevelopmentActionStore::class)->approve($companyId, (int) $action->id, (int) $hr->id);

    Livewire::actingAs($hr)->test(DevelopmentActionIndex::class)
        ->call('start', $action->id)
        ->assertHasNoErrors()
        ->assertNotDispatched('notify');

    expect($action->refresh()->status)->toBe(DevelopmentActionStatus::InProgress);

    $otherCompany = Company::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);
    $otherEmployees = coverageEmployees((int) $otherCompany->id, 4);
    ['skill' => $otherSkill] = coverageCatalog((int) $otherCompany->id, 'foreign');
    $foreign = coverageProposedAction((int) $otherCompany->id, $otherEmployees, $otherSkill, $hr);
    app(DevelopmentActionStore::class)->approve((int) $otherCompany->id, (int) $foreign->id, (int) $hr->id);

    expect(fn () => Livewire::actingAs($hr)->test(DevelopmentActionIndex::class)->call('start', $foreign->id))
        ->toThrow(ModelNotFoundException::class);

    Livewire::actingAs(coverageViewer($companyId, 'people.skill.development-action.view'))->test(DevelopmentActionIndex::class)
        ->call('start', $foreign->id)
        ->assertForbidden();

    expect($foreign->refresh()->status)->not->toBe(DevelopmentActionStatus::InProgress);
});

test('complete records the intervention with a reassessment date and refuses missing input and viewers', function (): void {
    ['company' => $companyId, 'hr' => $hr] = coverageHrTenant('Complete');
    $employees = coverageEmployees($companyId, 4);
    ['skill' => $skill] = coverageCatalog($companyId);
    $action = coverageProposedAction($companyId, $employees, $skill, $hr);
    $store = app(DevelopmentActionStore::class);
    $store->approve($companyId, (int) $action->id, (int) $hr->id);
    $store->start($companyId, (int) $action->id, (int) $hr->id);

    Livewire::actingAs($hr)->test(DevelopmentActionIndex::class)
        ->call('complete', $action->id)
        ->assertHasErrors(["reassessmentDue.{$action->id}"]);

    Livewire::actingAs($hr)->test(DevelopmentActionIndex::class)
        ->set("reassessmentDue.{$action->id}", now()->subDay()->toDateString())
        ->call('complete', $action->id)
        ->assertHasErrors(["reassessmentDue.{$action->id}"]);

    Livewire::actingAs($hr)->test(DevelopmentActionIndex::class)
        ->set("reassessmentDue.{$action->id}", now()->addMonth()->toDateString())
        ->call('complete', $action->id)
        ->assertHasErrors(['actions' => 'Completion evidence is required.']);

    expect($action->refresh()->status)->toBe(DevelopmentActionStatus::InProgress);

    Livewire::actingAs($hr)->test(DevelopmentActionIndex::class)
        ->set("reassessmentDue.{$action->id}", now()->addMonth()->toDateString())
        ->set("completionEvidence.{$action->id}", 'Signed observation checklist.')
        ->call('complete', $action->id)
        ->assertHasNoErrors()
        ->assertSet('completionEvidence', [])
        ->assertSet('reassessmentDue', []);

    expect($action->refresh()->status)->toBe(DevelopmentActionStatus::PendingReassessment)
        ->and($action->closure_status)->toBe(DevelopmentActionClosure::PendingReassessment);

    $second = coverageProposedAction($companyId, $employees, $skill, $hr);
    $store->approve($companyId, (int) $second->id, (int) $hr->id);

    Livewire::actingAs(coverageViewer($companyId, 'people.skill.development-action.view'))->test(DevelopmentActionIndex::class)
        ->set("reassessmentDue.{$second->id}", now()->addMonth()->toDateString())
        ->set("completionEvidence.{$second->id}", 'Not mine to complete.')
        ->call('complete', $second->id)
        ->assertForbidden();

    expect($second->refresh()->status)->not->toBe(DevelopmentActionStatus::PendingReassessment);
});

test('cancel closes an action with a reason and refuses a blank reason and viewers', function (): void {
    ['tenant' => $tenantId, 'company' => $companyId, 'hr' => $hr] = coverageHrTenant('Cancel');
    $employees = coverageEmployees($companyId, 4);
    ['skill' => $skill] = coverageCatalog($companyId);
    $action = coverageProposedAction($companyId, $employees, $skill, $hr);

    Livewire::actingAs($hr)->test(DevelopmentActionIndex::class)
        ->call('cancel', $action->id)
        ->assertHasErrors(['actions' => 'A cancellation reason is required.']);

    expect($action->refresh()->status)->toBe(DevelopmentActionStatus::Proposed);

    Livewire::actingAs(coverageViewer($companyId, 'people.skill.development-action.view'))->test(DevelopmentActionIndex::class)
        ->set("reason.{$action->id}", 'Not mine to cancel.')
        ->call('cancel', $action->id)
        ->assertForbidden();

    expect($action->refresh()->status)->toBe(DevelopmentActionStatus::Proposed);

    Livewire::actingAs($hr)->test(DevelopmentActionIndex::class)
        ->set("reason.{$action->id}", 'Employee transferred.')
        ->call('cancel', $action->id)
        ->assertHasNoErrors()
        ->assertSet('reason', []);

    expect($action->refresh()->status)->toBe(DevelopmentActionStatus::Cancelled)
        ->and($action->closure_status)->toBe(DevelopmentActionClosure::Cancelled)
        ->and(DevelopmentActionAuditEvent::query()->forCompany($tenantId, $companyId)->where('development_action_id', $action->id)->where('event_type', 'cancelled')->value('comment'))->toBe('Employee transferred.');
});

test('addComment appends an audit comment and refuses a blank comment and viewers', function (): void {
    ['tenant' => $tenantId, 'company' => $companyId, 'hr' => $hr] = coverageHrTenant('Comment');
    $employees = coverageEmployees($companyId, 4);
    ['skill' => $skill] = coverageCatalog($companyId);
    $action = coverageProposedAction($companyId, $employees, $skill, $hr);
    $comments = fn (): int => DevelopmentActionAuditEvent::query()->forCompany($tenantId, $companyId)->where('development_action_id', $action->id)->where('event_type', 'commented')->count();

    Livewire::actingAs($hr)->test(DevelopmentActionIndex::class)
        ->set("actionComment.{$action->id}", '   ')
        ->call('addComment', $action->id)
        ->assertHasErrors(['actions' => 'A comment is required.']);

    Livewire::actingAs(coverageViewer($companyId, 'people.skill.development-action.view'))->test(DevelopmentActionIndex::class)
        ->set("actionComment.{$action->id}", 'Not mine to say.')
        ->call('addComment', $action->id)
        ->assertForbidden();

    expect($comments())->toBe(0);

    Livewire::actingAs($hr)->test(DevelopmentActionIndex::class)
        ->set("actionComment.{$action->id}", 'Coach assigned; first session booked.')
        ->set("actionEvidence.{$action->id}", 'Calendar invite.')
        ->call('addComment', $action->id)
        ->assertHasNoErrors()
        ->assertSet('actionComment', [])
        ->assertSet('actionEvidence', []);

    $event = DevelopmentActionAuditEvent::query()->forCompany($tenantId, $companyId)->where('development_action_id', $action->id)->where('event_type', 'commented')->sole();

    expect($event->comment)->toBe('Coach assigned; first session booked.')
        ->and($event->evidence)->toBe('Calendar invite.');
});
