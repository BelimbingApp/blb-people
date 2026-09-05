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
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Data\AssessmentDraft;
use App\Domains\People\Skills\Data\DevelopmentActionDraft;
use App\Domains\People\Skills\Data\RequirementItemDraft;
use App\Domains\People\Skills\Data\RequirementProfileDraft;
use App\Domains\People\Skills\Data\RequirementSelectorDraft;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Database\Seeders\RequirementProfileWorkflowSeeder;
use App\Domains\People\Skills\Enums\AssessmentCycle;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\AssessmentResultBand;
use App\Domains\People\Skills\Enums\AssessmentStatus;
use App\Domains\People\Skills\Enums\DevelopmentActionType;
use App\Domains\People\Skills\Enums\HodVerification;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Enums\RequirementProfileStatus;
use App\Domains\People\Skills\Enums\SelectorType;
use App\Domains\People\Skills\Exceptions\InvalidAssessmentException;
use App\Domains\People\Skills\Exceptions\InvalidDevelopmentActionException;
use App\Domains\People\Skills\Exceptions\InvalidRequirementProfileException;
use App\Domains\People\Skills\Models\RequirementProfile;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\AssessmentStore;
use App\Domains\People\Skills\Services\AssessmentWorkflowContext;
use App\Domains\People\Skills\Services\DevelopmentActionStore;
use App\Domains\People\Skills\Services\RequirementProfileStore;
use App\Domains\People\Skills\Services\RequirementResolver;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\SkillAudienceAssignmentStore;
use App\Domains\People\Skills\Services\SkillCatalogDefaults;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\TwoCompanyTenant;

/*
|--------------------------------------------------------------------------
| Company-axis guards of the Skills stores (#131, batch 2)
|--------------------------------------------------------------------------
|
| Alpha and Beta share one tenant and each has its own catalog, published
| requirement profile, employees and records. Every test names the guard
| lines it turns red when they are deleted. Helpers are local to this file
| apart from the module's two-company support fixture.
*/

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/** @return array{tenant: TwoCompanyTenant, alpha: array<string, mixed>, beta: array<string, mixed>} */
function storeGuardFixture(): array
{
    // createTenant() bootstraps the tenant (workflow flow definitions included), which
    // the module's raw two-company fixture does not; the governed review path needs it.
    $created = createTenant(['name' => 'Store Guard Tenant']);
    $alphaCompany = Company::factory()->create(['tenant_id' => $created->id, 'name' => 'Alpha Industries', 'status' => 'active']);
    $betaCompany = Company::factory()->create(['tenant_id' => $created->id, 'name' => 'Beta Works', 'status' => 'active']);
    $tenant = new TwoCompanyTenant((int) $created->id, $alphaCompany, (int) $alphaCompany->id, $betaCompany, (int) $betaCompany->id);
    app(TenantContext::class)->set($tenant->tenantId);
    setupAuthzRoles();
    (new RequirementProfileWorkflowSeeder)->run();

    $sides = [];
    foreach (['alpha' => $tenant->alphaCompanyEntityId, 'beta' => $tenant->betaCompanyEntityId] as $label => $companyId) {
        $catalog = app(SkillCatalogStore::class);
        $category = $catalog->defineCategory($companyId, 'safety', ucfirst($label).' Safety');
        $skill = $catalog->defineSkill($companyId, new SkillDraft(
            code: 'forklift', name: ucfirst($label).' Forklift', definition: 'Operates a forklift.',
            categoryId: (int) $category->id, defaultAssessmentMethod: AssessmentMethod::DirectObservation,
        ));
        $employees = [];
        foreach (['Worker', 'Owner', 'Coordinator', 'Trainer'] as $role) {
            $employees[] = (int) Employee::factory()->create([
                'company_id' => $companyId, 'full_name' => ucfirst($label).' '.$role, 'status' => 'active', 'employee_type' => 'full_time',
            ])->id;
        }
        $hr = User::factory()->create(['company_id' => $companyId]);
        PrincipalRole::query()->create([
            'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $hr->id,
            'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_hr')->valueOrFail('id'),
        ]);
        $hod = User::factory()->create(['company_id' => $companyId]);
        PrincipalRole::query()->create([
            'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $hod->id,
            'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_hod')->valueOrFail('id'),
        ]);
        $hod->update(['employee_id' => $employees[1]]);
        EmployeePortalAccess::query()->create([
            'employee_id' => $employees[1], 'user_id' => $hod->id, 'display_name' => ucfirst($label).' Owner', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
        ]);

        $sides[$label] = ['company' => $companyId, 'skill' => $skill, 'employees' => $employees, 'hr' => $hr, 'hod' => $hod];
    }

    return ['tenant' => $tenant, 'alpha' => $sides['alpha'], 'beta' => $sides['beta']];
}

function storeGuardProfileDraft(Skill $skill, string $code = 'warehouse.operator'): RequirementProfileDraft
{
    return new RequirementProfileDraft(
        code: $code,
        name: 'Warehouse Operator',
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: [new RequirementItemDraft(skillId: (int) $skill->id, sequence: 1, requiredLevel: 3, criticality: RequirementCriticality::Critical, weightPercent: 100.0)],
    );
}

function storeGuardPublishedProfile(array $side, string $code = 'warehouse.operator'): RequirementProfile
{
    $store = app(RequirementProfileStore::class);

    return $store->publish($side['company'], (int) $store->draft($side['company'], storeGuardProfileDraft($side['skill'], $code))->id);
}

function storeGuardFinalizedAssessment(int $tenantId, array $side, int $level = 1): SkillAssessment
{
    $assessedAt = now()->subDay();
    $assessment = AssessmentWorkflowContext::runStoreMutation(static fn (): SkillAssessment => SkillAssessment::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $side['company'],
        'employee_entity_id' => $side['employees'][0], 'skill_id' => $side['skill']->id,
        'requirement_reference' => 'fixture', 'requirement_version' => 1, 'required_level' => 4,
        'criticality' => RequirementCriticality::Critical, 'weight_percent' => 100,
        'mandatory_gate' => true, 'assessed_level' => $level, 'gap' => 4 - $level,
        'weighted_gap' => (4 - $level) * 100, 'priority_score' => (4 - $level) * 300,
        'result_band' => AssessmentResultBand::fromGap(4 - $level, $level, 4),
        'method' => AssessmentMethod::DirectObservation, 'cycle' => AssessmentCycle::Annual,
        'status' => AssessmentStatus::Submitted, 'evidence' => 'Observed.', 'assessed_at' => $assessedAt,
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

    return $assessment;
}

function storeGuardActionDraft(array $side, array $overrides = []): DevelopmentActionDraft
{
    return new DevelopmentActionDraft(...array_merge([
        'employeeEntityId' => $side['employees'][0],
        'type' => DevelopmentActionType::Coaching,
        'objective' => 'Reach level four.',
        'intervention' => 'Coached cycles.',
        'expectedEvidence' => 'Checklist.',
        'ownerEmployeeEntityId' => $side['employees'][1],
        'hrCoordinatorEmployeeEntityId' => $side['employees'][2],
        'startDate' => now(),
        'dueDate' => now()->addDays(10),
        'trainerEmployeeEntityId' => $side['employees'][3],
        'skillId' => (int) $side['skill']->id,
        'startingLevel' => 1,
        'targetLevel' => 4,
        'criticality' => RequirementCriticality::Critical,
        'mandatoryGate' => true,
        'manualReason' => 'Fixture.',
    ], $overrides));
}

// ─── Requirement profiles ─────────────────────────────────────────────────────

test('profile versions, open versions and skill references are per company', function (): void {
    ['tenant' => $tenant, 'alpha' => $alpha, 'beta' => $beta] = storeGuardFixture();
    $store = app(RequirementProfileStore::class);
    storeGuardPublishedProfile($beta);
    $betaOpen = $store->draft($beta['company'], storeGuardProfileDraft($beta['skill']));

    // Guards: RequirementProfileStore::draft max(version) forCompany (line 62) and openVersionOf forCompany (line 474) —
    // Beta's published v1 and open v2 must not number Alpha's first draft or block it.
    $alphaDraft = $store->draft($alpha['company'], storeGuardProfileDraft($alpha['skill']));
    expect((int) $alphaDraft->version)->toBe(1)
        ->and($betaOpen->refresh()->status)->toBe(RequirementProfileStatus::Draft);

    // Guard: RequirementProfileStore::assertItems skill lookup forCompany (line 608).
    expect(fn () => $store->draft($alpha['company'], storeGuardProfileDraft($beta['skill'], 'foreign.skill')))
        ->toThrow(InvalidRequirementProfileException::class, 'was not found in this company');
});

test('publishing retires the same-code predecessor of the same company only', function (): void {
    ['tenant' => $tenant, 'alpha' => $alpha, 'beta' => $beta] = storeGuardFixture();
    $store = app(RequirementProfileStore::class);
    $betaPublished = storeGuardPublishedProfile($beta);
    $alphaV1 = storeGuardPublishedProfile($alpha);

    // Guard: RequirementProfile::publishedPredecessor forCompany (line 235) — publishing Alpha v2 retires Alpha v1, never Beta's.
    $alphaV2 = $store->publish($alpha['company'], (int) $store->newDraftFrom($alpha['company'], (int) $alphaV1->id)->id);

    expect($alphaV2->status)->toBe(RequirementProfileStatus::Published)
        ->and($alphaV1->refresh()->status)->toBe(RequirementProfileStatus::Retired)
        ->and($betaPublished->refresh()->status)->toBe(RequirementProfileStatus::Published)
        // Guard: currentProfile → publishedOf forCompany (line 488).
        ->and((int) $store->currentProfile($beta['company'], 'warehouse.operator')?->id)->toBe((int) $betaPublished->id);
});

test('overlap is judged against the same company published profiles only', function (): void {
    ['tenant' => $tenant, 'alpha' => $alpha, 'beta' => $beta] = storeGuardFixture();
    storeGuardPublishedProfile($beta, 'beta.wide');

    // Guard: RequirementProfileStore::assertNoOverlap published-profile lookup forCompany (line 629) — Beta's
    // company-wide profile must not count as an overlap for Alpha's company-wide profile under another code.
    $alphaWide = storeGuardPublishedProfile($alpha, 'alpha.wide');

    expect($alphaWide->status)->toBe(RequirementProfileStatus::Published);
});

test('the resolver matches only the employee company profiles', function (): void {
    ['tenant' => $tenant, 'alpha' => $alpha, 'beta' => $beta] = storeGuardFixture();
    storeGuardPublishedProfile($beta);
    $alphaProfile = storeGuardPublishedProfile($alpha);

    // Guard: RequirementResolver::resolve forCompany (line 48) — Beta's company-wide profile would otherwise
    // match every employee and make the resolution ambiguous.
    $resolved = app(RequirementResolver::class)->resolve(['company_entity_id' => $alpha['company'], 'employee_entity_id' => $alpha['employees'][0]]);

    expect((int) $resolved['profile']?->id)->toBe((int) $alphaProfile->id);
});

/** Drive one side's profile to PendingHrReview through a department-scoped HOD review. */
function storeGuardHrReviewProfile(array $side, string $label): RequirementProfile
{
    $unit = PeopleReferenceEntry::query()->create([
        'company_id' => $side['company'], 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'ops-'.$label, 'name' => ucfirst($label).' Operations', 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $type = DepartmentType::query()->create(['code' => 'ops-'.$label, 'name' => 'Operations', 'category' => 'operational', 'is_active' => true]);
    $department = Department::query()->create(['company_id' => $side['company'], 'department_type_id' => $type->id, 'head_id' => $side['employees'][1], 'status' => 'active']);
    foreach ([$side['employees'][0], $side['employees'][1]] as $employeeId) {
        Employee::query()->whereKey($employeeId)->update(['department_id' => $department->id]);
        EmployeeWorkProfile::query()->updateOrCreate(['employee_id' => $employeeId], ['organization_unit_id' => $unit->id]);
    }
    app(SkillAudienceAssignmentStore::class)->confirmActor($side['hr'], $side['hod'], $side['company'], $side['employees'][1], 'review:'.$label);

    $store = app(RequirementProfileStore::class);
    $profile = $store->draft($side['company'], new RequirementProfileDraft(
        code: 'dept.'.$label, name: ucfirst($label).' Department Profile',
        selectors: [new RequirementSelectorDraft(SelectorType::Department, null, (int) $unit->getKey())],
        items: [new RequirementItemDraft(skillId: (int) $side['skill']->id, sequence: 1, requiredLevel: 3, criticality: RequirementCriticality::Critical, weightPercent: 100.0)],
    ));
    $store->submitForReview($side['hr'], $side['company'], (int) $profile->id, 'Ready.');

    return $store->approveHod($side['hod'], $side['company'], (int) $profile->id, 'Technically sound.');
}

test('review queues and reviewer selection are bound to the company', function (): void {
    ['tenant' => $tenant, 'alpha' => $alpha, 'beta' => $beta] = storeGuardFixture();
    $store = app(RequirementProfileStore::class);
    $alphaPending = storeGuardHrReviewProfile($alpha, 'alpha');
    $betaPending = storeGuardHrReviewProfile($beta, 'beta');
    expect($alphaPending->status)->toBe(RequirementProfileStatus::PendingHrReview);

    // Guards: reviewQueue forCompany (line 375) — Beta HR asking for Alpha's queue must not be handed Beta's own
    // HR-review item through an unscoped read — and the mayGovernRequirements filter (line 387).
    expect($store->reviewQueue($beta['hr'], $alpha['company'])->pluck('id')->all())->toBe([])
        ->and($store->reviewQueue($alpha['hr'], $alpha['company'])->pluck('id')->all())->toBe([(int) $alphaPending->id]);

    // Guard: SkillAudience::requirementReviewerUserIds governance filter (line 100).
    $reviewers = app(SkillAudience::class)->requirementReviewerUserIds($alphaPending, RequirementProfileStatus::PendingHrReview);
    expect($reviewers)->toContain((int) $alpha['hr']->id)->not->toContain((int) $beta['hr']->id);
});

// ─── Assessments ──────────────────────────────────────────────────────────────

test('assessment submission and locking refuse sibling skills, assessments and scales', function (): void {
    ['tenant' => $tenant, 'alpha' => $alpha, 'beta' => $beta] = storeGuardFixture();
    app(SkillCatalogDefaults::class)->install($beta['company']);
    storeGuardPublishedProfile($alpha);
    $store = app(AssessmentStore::class);
    $betaAssessment = storeGuardFinalizedAssessment($tenant->tenantId, $beta);
    $draft = new AssessmentDraft(
        employeeEntityId: $alpha['employees'][0], skillId: (int) $beta['skill']->id, assessedLevel: 2,
        method: AssessmentMethod::DirectObservation, cycle: AssessmentCycle::Annual, assessedAt: now(),
        evidence: 'Observed.', notes: null, assessorUserId: (int) $alpha['hr']->id, weightPercent: 10.0,
    );

    // Guard: AssessmentStore::write skill lookup forCompany (line 399).
    expect(fn () => $store->draft($alpha['company'], $draft))
        ->toThrow(InvalidAssessmentException::class, 'was not found in this company catalog');

    // Guard: AssessmentStore::publishedScaleFallback forCompany (line 546) — only Beta has a published scale,
    // so Alpha's own skill still cannot be assessed.
    $ownSkill = new AssessmentDraft(
        employeeEntityId: $alpha['employees'][0], skillId: (int) $alpha['skill']->id, assessedLevel: 2,
        method: AssessmentMethod::DirectObservation, cycle: AssessmentCycle::Annual, assessedAt: now(),
        evidence: 'Observed.', notes: null, assessorUserId: (int) $alpha['hr']->id, weightPercent: 10.0,
    );
    expect(fn () => $store->draft($alpha['company'], $ownSkill))
        ->toThrow(InvalidAssessmentException::class, 'published proficiency scale is required');

    // Guard: AssessmentStore::lockAssessment forCompany (line 627) — Beta's assessment id is unknown to Alpha.
    expect(fn () => $store->requestHodVerification($alpha['hr'], $alpha['company'], (int) $betaAssessment->id))
        ->toThrow(InvalidAssessmentException::class, 'was not found');

    // Guards: SkillAudience::authorizeAssessmentSubmission deny (line 366) and authorizeAssessmentFinalization deny (line 398).
    expect(fn () => app(SkillAudience::class)->authorizeAssessmentSubmission($beta['hr'], $alpha['company'], $alpha['employees'][0]))
        ->toThrow(AuthorizationDeniedException::class)
        ->and(fn () => app(SkillAudience::class)->authorizeAssessmentFinalization($beta['hod'], $alpha['company'], $alpha['employees'][0]))
        ->toThrow(AuthorizationDeniedException::class);
});

// ─── Development actions ──────────────────────────────────────────────────────

test('development actions are found, drafted and registered within one company', function (): void {
    ['tenant' => $tenant, 'alpha' => $alpha, 'beta' => $beta] = storeGuardFixture();
    $store = app(DevelopmentActionStore::class);
    $betaAction = $store->proposeManual($beta['company'], storeGuardActionDraft($beta), (int) $beta['hr']->id);
    $alphaAction = $store->proposeManual($alpha['company'], storeGuardActionDraft($alpha), (int) $alpha['hr']->id);

    // Guard: DevelopmentActionStore::find forCompany (line 433).
    expect(fn () => $store->approve($alpha['company'], (int) $betaAction->id, (int) $alpha['hr']->id))
        ->toThrow(InvalidDevelopmentActionException::class, 'not found in this company');

    // Guard: DevelopmentActionStore::validateDraft skill lookup forCompany (line 368).
    expect(fn () => $store->proposeManual($alpha['company'], storeGuardActionDraft($alpha, ['skillId' => (int) $beta['skill']->id]), (int) $alpha['hr']->id))
        ->toThrow(InvalidDevelopmentActionException::class, 'skill was not found in this company');

    // Guards: operationalQuery (line 278) and terminalQuery (line 289) forCompany — Beta's open action, and
    // then its cancelled one, never appear in Alpha's registers.
    expect($store->operationalQuery($alpha['company'])->pluck('id')->all())->toBe([(int) $alphaAction->id]);
    $store->cancel($beta['company'], (int) $betaAction->id, 'Beta cancelled.', (int) $beta['hr']->id);
    expect($store->terminalQuery($alpha['company'])->pluck('id')->all())->toBe([]);

    // Guard: DevelopmentActionStore::linkReassessment assessment lookup forCompany (line 222).
    $store->approve($alpha['company'], (int) $alphaAction->id, (int) $alpha['hr']->id);
    $store->start($alpha['company'], (int) $alphaAction->id, (int) $alpha['hr']->id);
    $store->completeIntervention($alpha['company'], (int) $alphaAction->id, 'Done.', now()->addMonth(), (int) $alpha['hr']->id);
    $betaPost = storeGuardFinalizedAssessment($tenant->tenantId, $beta, 4);
    expect(fn () => $store->linkReassessment($alpha['company'], (int) $alphaAction->id, (int) $betaPost->id, (int) $alpha['hr']->id))
        ->toThrow(InvalidDevelopmentActionException::class, 'not found in this company');
});
