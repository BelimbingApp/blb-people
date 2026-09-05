<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Data\ProficiencyLevelDraft;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\ProficiencyScaleStatus;
use App\Domains\People\Skills\Exceptions\InvalidSkillAudienceAssignmentException;
use App\Domains\People\Skills\Exceptions\MissingCompanyScopeException;
use App\Domains\People\Skills\Livewire\Assessment\Matrix;
use App\Domains\People\Skills\Livewire\Catalog\Index as CatalogIndex;
use App\Domains\People\Skills\Livewire\DevelopmentAction\Index as DevelopmentActionIndex;
use App\Domains\People\Skills\Livewire\RequirementProfile\Show as RequirementProfileShow;
use App\Domains\People\Skills\Models\AssessmentDecision;
use App\Domains\People\Skills\Models\DevelopmentAction;
use App\Domains\People\Skills\Models\DevelopmentActionAuditEvent;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\ProficiencyScale;
use App\Domains\People\Skills\Models\ProficiencyScaleLevel;
use App\Domains\People\Skills\Models\RequirementItem;
use App\Domains\People\Skills\Models\RequirementProfile;
use App\Domains\People\Skills\Models\RequirementProfileSelector;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillActorBinding;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Models\SkillAssessorAssignment;
use App\Domains\People\Skills\Models\SkillCategory;
use App\Domains\People\Skills\Services\ProficiencyScaleStore;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\SkillAudienceAssignmentStore;
use App\Domains\People\Skills\Services\SkillCatalogDefaults;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\CompanyIsolationFixture;
use App\Domains\People\Skills\Tests\Support\TwoCompanyTenant;
use Illuminate\View\ViewException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Company-axis guards of the relocated Skills module (#131)
|--------------------------------------------------------------------------
|
| Alpha and Beta share one tenant. Each test below is the failing test for
| one or more guards that a deletion matrix over the module found with no
| red test: the guard line is named in the test body. Helpers are local to
| this file apart from the module's own two-company support fixture.
*/

beforeEach(function (): void {
    $this->withoutVite();
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function axisTenant(): TwoCompanyTenant
{
    $fixture = CompanyIsolationFixture::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);
    setupAuthzRoles();

    return $fixture;
}

function axisUser(int $companyId, string $roleCode): User
{
    $user = User::factory()->create(['company_id' => $companyId]);
    PrincipalRole::query()->create([
        'company_id' => $companyId,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->valueOrFail('id'),
    ]);

    return $user;
}

function axisEmployee(int $companyId, string $name): Employee
{
    return Employee::factory()->create(['company_id' => $companyId, 'full_name' => $name, 'status' => 'active', 'employee_type' => 'full_time']);
}

function axisBind(User $user, Employee $employee): void
{
    $user->update(['employee_id' => $employee->id]);
    EmployeePortalAccess::query()->updateOrCreate(
        ['employee_id' => $employee->id],
        ['user_id' => $user->id, 'display_name' => $employee->displayName(), 'status' => EmployeePortalAccess::STATUS_ACTIVE],
    );
}

/** @return array{category: SkillCategory, skill: Skill} */
function axisCatalog(int $companyId, string $code, string $label): array
{
    $store = app(SkillCatalogStore::class);
    $category = $store->defineCategory($companyId, 'safety', $label.' Safety');
    $skill = $store->defineSkill($companyId, new SkillDraft(
        code: $code, name: $label.' '.$code, definition: 'Works to the standard.',
        categoryId: (int) $category->id, defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));

    return ['category' => $category, 'skill' => $skill];
}

/** @return list<ProficiencyLevelDraft> */
function axisLevels(): array
{
    return [
        new ProficiencyLevelDraft(0, 'None', 'No demonstrated capability.', 'None.'),
        new ProficiencyLevelDraft(1, 'Full', 'Works unsupervised.', 'Authorised.'),
    ];
}

function axisPublishedScale(int $companyId, string $code, string $name): ProficiencyScale
{
    $store = app(ProficiencyScaleStore::class);

    return $store->publish($companyId, (int) $store->draft($companyId, $code, $name, axisLevels())->id);
}

// ─── Models ───────────────────────────────────────────────────────────────────

test('every company-owned Skills model refuses a query that pins only the tenant', function (string $model): void {
    $fixture = axisTenant();

    // Guard: `use CompanyOwned` on the model (RequireCompanyScope).
    expect(fn () => $model::query()->forTenant($fixture->tenantId)->get())
        ->toThrow(MissingCompanyScopeException::class, 'company');
})->with([
    AssessmentDecision::class, DevelopmentAction::class, DevelopmentActionAuditEvent::class, EmployeeSkillScore::class,
    ProficiencyScale::class, ProficiencyScaleLevel::class, RequirementItem::class, RequirementProfile::class,
    RequirementProfileSelector::class, Skill::class, SkillActorBinding::class, SkillAssessment::class,
    SkillAssessorAssignment::class, SkillCategory::class,
]);

// ─── Catalog and scale stores ─────────────────────────────────────────────────

test('a skill code is unique per company, so siblings may share one', function (): void {
    $fixture = axisTenant();

    // Guard: SkillCatalogStore::defineSkill duplicate-code lookup forCompany (line 88).
    axisCatalog($fixture->alphaCompanyEntityId, 'forklift.operation', 'Alpha');
    axisCatalog($fixture->betaCompanyEntityId, 'forklift.operation', 'Beta');

    expect(Skill::query()->forCompany($fixture->tenantId, $fixture->alphaCompanyEntityId)->where('code', 'forklift.operation')->count())->toBe(1)
        ->and(Skill::query()->forCompany($fixture->tenantId, $fixture->betaCompanyEntityId)->where('code', 'forklift.operation')->count())->toBe(1);
});

test('installing the starter pack for one company ignores what its sibling already installed', function (): void {
    $fixture = axisTenant();
    $defaults = app(SkillCatalogDefaults::class);

    $beta = $defaults->install($fixture->betaCompanyEntityId);
    // Guard: SkillCatalogDefaults::install category existence check forCompany (line 55),
    // and ProficiencyScaleStore::currentScale → publishedOf forCompany (line 208).
    $alpha = $defaults->install($fixture->alphaCompanyEntityId);

    expect($alpha['categories'])->toBe($beta['categories'])
        ->and($alpha['categories'])->toBeGreaterThan(0)
        ->and($alpha['scale'])->not->toBeNull()
        ->and((int) $alpha['scale']->company_entity_id)->toBe($fixture->alphaCompanyEntityId);
});

test('scale version numbering and publication supersession are per company', function (): void {
    $fixture = axisTenant();
    $store = app(ProficiencyScaleStore::class);
    $betaV1 = axisPublishedScale($fixture->betaCompanyEntityId, 'std', 'Beta Standard');
    $betaV2 = axisPublishedScale($fixture->betaCompanyEntityId, 'std', 'Beta Standard');

    // Guard: ProficiencyScaleStore::draft max(version) forCompany (line 51).
    $alphaDraft = $store->draft($fixture->alphaCompanyEntityId, 'std', 'Alpha Standard', axisLevels());
    expect((int) $alphaDraft->version)->toBe(1);

    // Guard: ProficiencyScaleStore::publishedOf forCompany (line 208) — publishing
    // Alpha's scale must retire Alpha's predecessor only, never Beta's.
    $store->publish($fixture->alphaCompanyEntityId, (int) $alphaDraft->id);

    expect($betaV2->refresh()->status)->toBe(ProficiencyScaleStatus::Published)
        ->and($betaV1->refresh()->status)->toBe(ProficiencyScaleStatus::Retired)
        ->and($alphaDraft->refresh()->status)->toBe(ProficiencyScaleStatus::Published);
});

// ─── Actor bindings and assessor assignments ──────────────────────────────────

test('actor bindings are confirmed, revoked and assigned only by that company HR for that company people', function (): void {
    $fixture = axisTenant();
    $alphaHr = axisUser($fixture->alphaCompanyEntityId, 'people_hr');
    $betaHr = axisUser($fixture->betaCompanyEntityId, 'people_hr');
    $alphaHod = axisUser($fixture->alphaCompanyEntityId, 'people_hod');
    $betaHod = axisUser($fixture->betaCompanyEntityId, 'people_hod');
    $alphaHead = axisEmployee($fixture->alphaCompanyEntityId, 'Alpha Head');
    $betaHead = axisEmployee($fixture->betaCompanyEntityId, 'Beta Head');
    axisBind($alphaHod, $alphaHead);
    axisBind($betaHod, $betaHead);
    $store = app(SkillAudienceAssignmentStore::class);

    // Guards: confirmActor assertHr (line 34) → SkillAudience::assertHr deny (277) via may() attribution (418).
    expect(fn () => $store->confirmActor($betaHr, $alphaHod, $fixture->alphaCompanyEntityId, (int) $alphaHead->id, 'review:x'))
        ->toThrow(AuthorizationDeniedException::class);

    // Guard: confirmActor mayActFor on the platform user (line 35).
    expect(fn () => $store->confirmActor($alphaHr, $betaHod, $fixture->alphaCompanyEntityId, (int) $betaHead->id, 'review:x'))
        ->toThrow(InvalidSkillAudienceAssignmentException::class, 'outside the workforce company boundary');

    $store->confirmActor($alphaHr, $alphaHod, $fixture->alphaCompanyEntityId, (int) $alphaHead->id, 'review:alpha');
    $betaBinding = $store->confirmActor($betaHr, $betaHod, $fixture->betaCompanyEntityId, (int) $betaHead->id, 'review:beta');

    // Guard: revokeActor assertHr (line 79).
    expect(fn () => $store->revokeActor($betaHr, $fixture->alphaCompanyEntityId, (int) $alphaHod->id, 'review:revoke'))
        ->toThrow(AuthorizationDeniedException::class);

    // Guard: revokeActor binding lookup forCompany (line 81) — Alpha HR naming Beta's user id revokes nothing.
    $store->revokeActor($alphaHr, $fixture->alphaCompanyEntityId, (int) $betaHod->id, 'review:revoke');
    expect($betaBinding->refresh()->revoked_at)->toBeNull();

    // Guard: assignAssessor assertHr (line 109).
    expect(fn () => $store->assignAssessor($betaHr, $alphaHod, $fixture->alphaCompanyEntityId, (int) $alphaHead->id, 'review:assess'))
        ->toThrow(AuthorizationDeniedException::class);

    // Guard: assignAssessor mayActFor on the assessor (line 110).
    expect(fn () => $store->assignAssessor($alphaHr, $betaHod, $fixture->alphaCompanyEntityId, (int) $alphaHead->id, 'review:assess'))
        ->toThrow(InvalidSkillAudienceAssignmentException::class, 'assessor is outside the workforce company boundary');

    expect(SkillAssessorAssignment::query()->forCompany($fixture->tenantId, $fixture->alphaCompanyEntityId)->count())->toBe(0);
});

test('employee and organisation-unit visibility fail closed for a sibling company HR', function (): void {
    $fixture = axisTenant();
    $betaHr = axisUser($fixture->betaCompanyEntityId, 'people_hr');
    $alphaWorker = axisEmployee($fixture->alphaCompanyEntityId, 'Alpha Worker');
    // The worker sits in a unit, so an unscoped HR read would have a unit to leak.
    $unit = PeopleReferenceEntry::query()->create([
        'company_id' => $fixture->alphaCompanyEntityId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'ops-alpha', 'name' => 'Alpha Operations', 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    EmployeeWorkProfile::query()->create(['employee_id' => $alphaWorker->id, 'organization_unit_id' => $unit->id]);
    $audience = app(SkillAudience::class);

    // Guard: scopedEmployeeEntityIds mayActFor (line 158) — the HR branch would otherwise list every Alpha employee.
    expect($audience->visibleEmployeeEntityIds($betaHr, $fixture->alphaCompanyEntityId, manage: true))->toBe([]);

    // Guard: visibleOrganizationUnitEntityIds mayActFor (line 218) — returns nothing rather than Alpha's units.
    expect($audience->visibleOrganizationUnitEntityIds($betaHr, $fixture->alphaCompanyEntityId, 'people.skill.catalog.view'))->toBe([]);
});

// ─── Screens ──────────────────────────────────────────────────────────────────

test('the catalog, matrix and development-action screens refuse a sibling company and users without the audience', function (): void {
    $fixture = axisTenant();
    $alphaHr = axisUser($fixture->alphaCompanyEntityId, 'people_hr');
    axisCatalog($fixture->alphaCompanyEntityId, 'alpha.skill', 'Alpha');
    axisCatalog($fixture->betaCompanyEntityId, 'beta.secret.skill', 'Beta');
    axisPublishedScale($fixture->alphaCompanyEntityId, 'std', 'Alpha Standard Scale');
    axisPublishedScale($fixture->betaCompanyEntityId, 'std', 'Beta Secret Scale');

    // Guard: Matrix::selectCompany attribution abort (line 57); DevelopmentAction\Index::selectCompany (line 87).
    Livewire::actingAs($alphaHr)->test(Matrix::class)->call('selectCompany', $fixture->betaCompanyEntityId)->assertStatus(404);
    Livewire::actingAs($alphaHr)->test(DevelopmentActionIndex::class)->call('selectCompany', $fixture->betaCompanyEntityId)->assertStatus(404);

    // Guard: Matrix::skills forCompany (line 184); Catalog\Index::scales forCompany (line 315).
    Livewire::actingAs($alphaHr)->test(Matrix::class)->assertSee('alpha.skill')->assertDontSee('beta.secret.skill');
    Livewire::actingAs($alphaHr)->test(CatalogIndex::class)->set('tab', 'scales')->assertSee('Alpha Standard Scale')->assertDontSee('Beta Secret Scale');

    // Guard: Catalog\Index::authorizeView (line 331) — no skill audience at all.
    $nobody = User::factory()->create(['company_id' => $fixture->alphaCompanyEntityId]);
    Livewire::actingAs($nobody)->test(CatalogIndex::class)->assertForbidden();

    // Guard: SkillAudience::allowedCompanies authorize (line 58) through the requirement-profile screen's mount.
    // Livewire wraps a mount-time exception in a ViewException; the cause is the denial.
    expect(fn () => Livewire::actingAs($nobody)->test(RequirementProfileShow::class, ['profileId' => PHP_INT_MAX]))
        ->toThrow(ViewException::class, 'Authorization denied');

    // Guard: DevelopmentAction\Index::canManage authorize (line 343) — a viewer sees no manage affordance.
    $viewer = User::factory()->create(['company_id' => $fixture->alphaCompanyEntityId]);
    foreach (['people.skill.development-action.view', 'people.skill.hr.view'] as $capability) {
        PrincipalCapability::query()->create([
            'company_id' => $fixture->alphaCompanyEntityId, 'principal_type' => PrincipalType::USER->value,
            'principal_id' => $viewer->id, 'capability_key' => $capability, 'is_allowed' => true,
        ]);
    }
    Livewire::actingAs($viewer)->test(DevelopmentActionIndex::class)->assertViewHas('canManage', false);
    Livewire::actingAs($alphaHr)->test(DevelopmentActionIndex::class)->assertViewHas('canManage', true);
});
