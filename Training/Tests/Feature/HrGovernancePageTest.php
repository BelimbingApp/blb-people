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
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Data\RequirementItemDraft;
use App\Domains\People\Skills\Data\RequirementProfileDraft;
use App\Domains\People\Skills\Data\RequirementSelectorDraft;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Database\Seeders\RequirementProfileWorkflowSeeder;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Enums\RequirementProfileStatus;
use App\Domains\People\Skills\Enums\SelectorType;
use App\Domains\People\Skills\Enums\SkillScope;
use App\Domains\People\Skills\Models\RequirementProfile;
use App\Domains\People\Skills\Services\RequirementProfileStore;
use App\Domains\People\Skills\Services\SkillAudienceAssignmentStore;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use App\Domains\People\Skills\Workflow\RequirementProfileTransitionAuthority;
use App\Domains\People\Training\Data\TrainingPlanDraft;
use App\Domains\People\Training\Data\TrainingPlanItemDraft;
use App\Domains\People\Training\Data\TrainingRequestDraft;
use App\Domains\People\Training\Enums\TrainingDeliveryApproach;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingPlanStatus;
use App\Domains\People\Training\Enums\TrainingPriority;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Livewire\HrGovernance\Index;
use App\Domains\People\Training\Models\TrainingPlan;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Services\TrainingPlanStore;
use App\Domains\People\Training\Services\TrainingRequestStore;
use Livewire\Livewire;

/**
 * HR governance page (0005-f): lists what awaits HR in the acting user's
 * company only, and routes every decision through the owning store.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

function hrGovUser(Company $company, string $roleCode): User
{
    $user = User::factory()->create(['company_id' => $company->id]);
    PrincipalRole::query()->create([
        'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->sole()->id,
    ]);

    return $user;
}

/**
 * One company side: HR and HOD users, a department the HOD heads, and one
 * pending item of each kind (requirement profile at HR review, training
 * request at HR review, training plan submitted).
 *
 * @return array{company: Company, hr: User, hod: User, profile: RequirementProfile, request: TrainingRequest, plan: TrainingPlan}
 */
function hrGovSide(int $tenantId, Company $company, string $label): array
{
    $hr = hrGovUser($company, 'people_hr');
    $hod = hrGovUser($company, 'people_hod');

    // Department headed by the HOD, so plan submission and request
    // recommendation are inside the HOD's scope.
    $entry = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'OPS-'.$label, 'name' => 'Operations '.$label, 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $type = DepartmentType::query()->firstOrCreate(['code' => 'ops-gov'], ['name' => 'Operations governance', 'category' => 'operational', 'is_active' => true]);
    $department = Department::query()->create(['company_id' => $company->id, 'department_type_id' => $type->id, 'status' => 'active']);
    $head = Employee::factory()->create(['company_id' => $company->id, 'department_id' => $department->id, 'full_name' => 'Head '.$label, 'status' => 'active', 'employee_type' => 'full_time']);
    $department->update(['head_id' => $head->id]);
    EmployeeWorkProfile::query()->create(['employee_id' => $head->id, 'organization_unit_id' => $entry->id]);
    $hod->update(['employee_id' => $head->id]);
    EmployeePortalAccess::query()->create(['employee_id' => $head->id, 'user_id' => $hod->id, 'display_name' => 'Head '.$label, 'status' => EmployeePortalAccess::STATUS_ACTIVE]);
    app(SkillAudienceAssignmentStore::class)->confirmActor($hr, $hod, (int) $company->id, (int) $head->id, 'review:hr-gov-'.$label);

    // Requirement profile taken to HR review through the transition authority,
    // the same path the store's own fixture shortcut uses.
    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory((int) $company->id, 'gov-'.strtolower($label), 'Governance '.$label);
    $skill = $catalog->defineSkill((int) $company->id, new SkillDraft(
        code: 'gov.skill', name: 'Governed skill '.$label, definition: 'Governed.', categoryId: (int) $category->id,
        scope: SkillScope::Shared, defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));
    $profiles = app(RequirementProfileStore::class);
    $profile = $profiles->draft((int) $company->id, new RequirementProfileDraft(
        code: 'gov.profile', name: 'Governed profile '.$label,
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: [new RequirementItemDraft(skillId: (int) $skill->id, sequence: 1, requiredLevel: 3, criticality: RequirementCriticality::Critical, weightPercent: 100.0)],
    ));
    $authority = app(RequirementProfileTransitionAuthority::class);
    foreach ([RequirementProfileStatus::PendingHodReview, RequirementProfileStatus::PendingHrReview] as $next) {
        $authority->authorize($profile, $profile->status, $next);
        $profile->update(['status' => $next]);
    }

    // Training request recommended by the HOD, so it now awaits HR review.
    $employee = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, (int) $company->id);
    $requests = app(TrainingRequestStore::class);
    $request = $requests->create($hr, (int) $company->id, new TrainingRequestDraft(
        requestor: new WorkforceSubject($tenantId, (int) $company->id, WorkforceResourceType::Employee, (string) $employee->id),
        department: new WorkforceSubject($tenantId, (int) $company->id, WorkforceResourceType::OrganizationUnit, (string) $entry->id),
        needSource: TrainingNeedSource::NewMachineTechnology,
        need: 'Need '.$label.': operate the new control system.',
        learningObjective: 'Operate the control system safely.',
        expectedResult: 'Zero unsafe startups.',
        priority: TrainingPriority::High,
    ));
    $requests->submit($hr, (int) $company->id, (int) $request->id);
    $request = $requests->recommend($hod, (int) $company->id, (int) $request->id, 'Relevant.');

    // Training plan submitted by the HOD.
    $plans = app(TrainingPlanStore::class);
    $plan = $plans->createDraft($hod, (int) $company->id, new TrainingPlanDraft(
        departmentEntityId: (int) $entry->id,
        periodStart: new DateTimeImmutable('2027-01-01'),
        periodEnd: new DateTimeImmutable('2027-12-31'),
        objectives: 'Objectives '.$label.'.',
        financialTrackingEnabled: false,
        items: [new TrainingPlanItemDraft(
            needTenantId: $tenantId, needCompanyEntityId: (int) $company->id, needReference: 'development-action:GOV-'.$label,
            expectedResult: 'Demonstrated.', targetCohort: 'Technicians', deliveryApproach: TrainingDeliveryApproach::Mixed,
            responsibleOwnerReference: 'employee:'.$head->id, intendedTiming: '2027-Q1', evaluationApproach: 'Observed.', budgetLine: null,
        )],
    ));
    $plan = $plans->submit($hod, (int) $company->id, (int) $plan->id);

    return ['company' => $company, 'hr' => $hr, 'hod' => $hod, 'profile' => $profile->fresh(), 'request' => $request, 'plan' => $plan];
}

/**
 * @return array{tenantId: int, alpha: array, beta: array}
 */
function hrGovFixture(): array
{
    $tenant = createTenant(['name' => 'HR Governance Tenant']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();
    (new RequirementProfileWorkflowSeeder)->run();
    $alpha = Company::factory()->create(['tenant_id' => $tenantId, 'name' => 'Alpha Governance', 'status' => 'active']);
    $beta = Company::factory()->create(['tenant_id' => $tenantId, 'name' => 'Beta Governance', 'status' => 'active']);

    return ['tenantId' => $tenantId, 'alpha' => hrGovSide($tenantId, $alpha, 'Alpha'), 'beta' => hrGovSide($tenantId, $beta, 'Beta')];
}

test('the HR queue lists only the acting company\'s pending items, and nothing of a sibling company', function (): void {
    $f = hrGovFixture();

    $page = Livewire::actingAs($f['alpha']['hr'])->test(Index::class)->assertOk();

    expect($page->viewData('profiles')->pluck('id')->all())->toBe([$f['alpha']['profile']->id])
        ->and($page->viewData('requests')->pluck('id')->all())->toBe([$f['alpha']['request']->id])
        ->and($page->viewData('plans')->pluck('id')->all())->toBe([$f['alpha']['plan']->id]);

    $page->assertSee('Governed profile Alpha')
        ->assertSee('Need Alpha')
        ->assertSee('Objectives Alpha.')
        ->assertDontSee('Governed profile Beta')
        ->assertDontSee('Need Beta')
        ->assertDontSee('Objectives Beta.');
});

test('HR approves a profile, forwards a request and approves a plan through the owning stores', function (): void {
    $f = hrGovFixture();
    $a = $f['alpha'];

    Livewire::actingAs($a['hr'])->test(Index::class)
        ->set('profileComment.'.$a['profile']->id, 'Reviewed by HR.')
        ->call('approveProfile', $a['profile']->id)
        ->set('requestNotes.'.$a['request']->id, 'Forwarded.')
        ->call('reviewRequest', $a['request']->id)
        ->call('approvePlan', $a['plan']->id);

    expect($a['profile']->fresh()->status)->toBe(RequirementProfileStatus::Approved)
        ->and($a['request']->fresh()->status)->toBe(TrainingRequestStatus::PendingApproval)
        ->and($a['plan']->fresh()->status)->toBe(TrainingPlanStatus::Approved);

    // The approved profile now offers publication; publishing it lands it.
    Livewire::actingAs($a['hr'])->test(Index::class)
        ->assertSee('Publish')
        ->call('publishProfile', $a['profile']->id);

    expect($a['profile']->fresh()->status)->toBe(RequirementProfileStatus::Published);
});

test('HR returns a profile to draft and rejects a request with notes', function (): void {
    $f = hrGovFixture();
    $a = $f['alpha'];

    Livewire::actingAs($a['hr'])->test(Index::class)
        ->set('profileComment.'.$a['profile']->id, 'Needs a mandatory gate.')
        ->call('returnProfile', $a['profile']->id)
        ->set('requestNotes.'.$a['request']->id, 'Not this quarter.')
        ->call('rejectRequest', $a['request']->id);

    expect($a['profile']->fresh()->status)->toBe(RequirementProfileStatus::Draft)
        ->and($a['request']->fresh()->status)->toBe(TrainingRequestStatus::Rejected);
});

test('an HR user cannot act on a sibling company\'s items by id', function (): void {
    $f = hrGovFixture();
    $b = $f['beta'];

    // The owning stores refuse a sibling company's id (not found in this
    // company); the page's recovery hook turns that into an error toast, so
    // the proof is the untouched state, not an escaped exception.
    foreach ([
        ['approveProfile', $b['profile']->id, 'profileComment.'.$b['profile']->id],
        ['reviewRequest', $b['request']->id, 'requestNotes.'.$b['request']->id],
        ['approvePlan', $b['plan']->id, null],
    ] as [$action, $id, $field]) {
        $page = Livewire::actingAs($f['alpha']['hr'])->test(Index::class);
        if ($field !== null) {
            $page->set($field, 'Crafted.');
        }
        $page->call($action, $id);
    }

    expect($b['profile']->fresh()->status)->toBe(RequirementProfileStatus::PendingHrReview)
        ->and($b['request']->fresh()->status)->toBe(TrainingRequestStatus::PendingHr)
        ->and($b['plan']->fresh()->status)->toBe(TrainingPlanStatus::Submitted);
});

test('an approval without the capability is refused by the owning store', function (): void {
    $f = hrGovFixture();
    $a = $f['alpha'];

    // An HR-audience user whose approving capabilities are explicitly denied:
    // the page still lists, the owning stores still refuse.
    $viewer = hrGovUser($a['company'], 'people_hr');
    foreach ([TrainingRequestStore::HR_REVIEW, 'people.training.plan.approve'] as $capability) {
        PrincipalCapability::query()->create([
            'company_id' => $a['company']->id, 'principal_type' => PrincipalType::USER->value,
            'principal_id' => $viewer->id, 'capability_key' => $capability, 'is_allowed' => false,
        ]);
    }

    $viewerPage = Livewire::actingAs($viewer)->test(Index::class)->assertOk();
    expect($viewerPage->viewData('requests'))->toHaveCount(1);

    // A refused call ends the component snapshot, so each action gets its own instance.
    Livewire::actingAs($viewer)->test(Index::class)
        ->set('requestNotes.'.$a['request']->id, 'Trying.')
        ->call('reviewRequest', $a['request']->id)
        ->assertForbidden();
    Livewire::actingAs($viewer)->test(Index::class)
        ->call('approvePlan', $a['plan']->id)
        ->assertForbidden();

    expect($a['request']->fresh()->status)->toBe(TrainingRequestStatus::PendingHr)
        ->and($a['plan']->fresh()->status)->toBe(TrainingPlanStatus::Submitted);
});

test('a forged company id is neither listed nor acted on', function (): void {
    $f = hrGovFixture();
    $beta = $f['beta'];

    // Setting the public property directly, as a client could, is refused on
    // the render that follows; choosing it through the action is refused too.
    Livewire::actingAs($f['alpha']['hr'])->test(Index::class)
        ->set('companyEntityId', $beta['company']->id)
        ->assertNotFound();
    Livewire::actingAs($f['alpha']['hr'])->test(Index::class)
        ->call('selectCompany', $beta['company']->id)
        ->assertNotFound();

    expect($beta['plan']->fresh()->status)->toBe(TrainingPlanStatus::Submitted);
});

test('the route refuses a HOD, a stranger and a platform admin', function (): void {
    $f = hrGovFixture();
    $a = $f['alpha'];

    $this->actingAs($a['hr'])->get(route('people.hr-governance.index'))->assertOk();
    $this->actingAs($a['hod'])->get(route('people.hr-governance.index'))->assertForbidden();
    $this->actingAs(User::factory()->create(['company_id' => $a['company']->id]))->get(route('people.hr-governance.index'))->assertForbidden();
    $this->actingAs(hrGovUser($a['company'], 'core_admin'))->get(route('people.hr-governance.index'))->assertForbidden();
});
