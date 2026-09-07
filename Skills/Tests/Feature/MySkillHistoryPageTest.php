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
use App\Domains\People\Skills\Contracts\ResolvesSkillRequirements;
use App\Domains\People\Skills\Data\AssessmentDraft;
use App\Domains\People\Skills\Data\RequirementItemDraft;
use App\Domains\People\Skills\Data\RequirementProfileDraft;
use App\Domains\People\Skills\Data\RequirementSelectorDraft;
use App\Domains\People\Skills\Data\ResolvedSkillRequirement;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentCycle;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Enums\SelectorType;
use App\Domains\People\Skills\Livewire\MyHistory\Index as MySkillHistory;
use App\Domains\People\Skills\Services\AssessmentStore;
use App\Domains\People\Skills\Services\RequirementProfileStore;
use App\Domains\People\Skills\Services\SkillAudienceAssignmentStore;
use App\Domains\People\Skills\Services\SkillCatalogDefaults;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use Livewire\Livewire;

/**
 * 0006-a: an employee reads their own released score history.
 * Self-contained: helpers are prefixed mh and live here.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function mhFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'History Tenant'], ['name' => 'History Company', 'status' => 'active']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $unit = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'MH-OPS', 'name' => 'History operations', 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $type = DepartmentType::query()->create([
        'code' => 'mh-ops', 'name' => 'History operations', 'category' => 'operational', 'is_active' => true,
    ]);
    $department = Department::query()->create(['company_id' => $companyId, 'department_type_id' => $type->id, 'status' => 'active']);

    $head = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id,
        'full_name' => 'History Head', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $department->update(['head_id' => $head->id]);
    EmployeeWorkProfile::query()->create(['employee_id' => $head->id, 'organization_unit_id' => $unit->id]);

    $employee = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id, 'supervisor_id' => $head->id,
        'full_name' => 'History Employee', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    EmployeeWorkProfile::query()->create(['employee_id' => $employee->id, 'organization_unit_id' => $unit->id]);

    $hr = User::factory()->create(['company_id' => $companyId, 'name' => 'History HR']);
    $hod = User::factory()->create(['company_id' => $companyId, 'employee_id' => $head->id, 'name' => 'History HOD']);
    $self = User::factory()->create(['company_id' => $companyId, 'employee_id' => $employee->id, 'name' => 'History Self']);
    EmployeePortalAccess::query()->create([
        'employee_id' => $head->id, 'user_id' => $hod->id,
        'display_name' => 'History Head', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $employee->id, 'user_id' => $self->id,
        'display_name' => 'History Employee', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    foreach ([[$hr, 'people_hr'], [$hod, 'people_hod'], [$self, 'people_employee']] as [$actor, $code]) {
        PrincipalRole::query()->create([
            'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
            'principal_id' => $actor->id,
            'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->valueOrFail('id'),
        ]);
    }
    app(SkillAudienceAssignmentStore::class)->confirmActor($hr, $hod, $companyId, (int) $head->id, 'review:history-hod');
    app(SkillAudienceAssignmentStore::class)->confirmActor($hr, $self, $companyId, (int) $employee->id, 'review:history-self');

    return compact('tenantId', 'companyId', 'hr', 'hod', 'self', 'employee', 'department', 'unit');
}

function mhSkill(array $f, string $code, string $name): int
{
    $category = app(SkillCatalogStore::class)->defineCategory($f['companyId'], 'mh-'.$code, 'History '.$name);

    return (int) app(SkillCatalogStore::class)->defineSkill($f['companyId'], new SkillDraft(
        code: 'mh.'.$code, name: $name, definition: 'Isolate before maintenance.', categoryId: (int) $category->id,
    ))->id;
}

final class MhRequirements implements ResolvesSkillRequirements
{
    /** @param list<ResolvedSkillRequirement> $rows */
    public function __construct(private array $rows) {}

    public function requirementsFor(array $employeeData, ?DateTimeInterface $asOf = null): array
    {
        return $this->rows;
    }
}

function mhRequirement(array $f, array $skillIds): void
{
    app(SkillCatalogDefaults::class)->install($f['companyId']);
    $profiles = app(RequirementProfileStore::class);
    $sequence = 0;
    $items = [];
    $weight = 100.0 / max(1, count($skillIds));
    foreach ($skillIds as $skillId) {
        $sequence++;
        $items[] = new RequirementItemDraft(
            skillId: $skillId,
            sequence: $sequence,
            requiredLevel: 4,
            criticality: RequirementCriticality::Critical,
            weightPercent: $weight,
        );
    }
    $profile = $profiles->draft($f['companyId'], new RequirementProfileDraft(
        code: 'fixture.mh-ops',
        name: 'MH operations',
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: $items,
    ));
    $profile = $profiles->publish($f['companyId'], (int) $profile->id);
    $rows = [];
    foreach ($skillIds as $skillId) {
        $rows[] = new ResolvedSkillRequirement(
            requirementReference: 'fixture.mh',
            requirementVersion: 1,
            requirementProfileId: (int) $profile->id,
            skillId: $skillId,
            requiredLevel: 4,
            criticality: RequirementCriticality::Critical,
            mandatoryGate: true,
        );
    }
    app()->instance(ResolvesSkillRequirements::class, new MhRequirements($rows));
}

function mhFinalized(array $f, Employee $employee, int $skillId, int $level, string $assessedAt, ?string $validUntil = null): void
{
    $store = app(AssessmentStore::class);
    $submitted = $store->submit($f['hr'], $f['companyId'], new AssessmentDraft(
        employeeEntityId: (int) $employee->id,
        skillId: $skillId,
        assessedLevel: $level,
        method: AssessmentMethod::DirectObservation,
        cycle: AssessmentCycle::Annual,
        assessedAt: now()->parse($assessedAt),
        evidence: 'Observed task.',
        validUntil: $validUntil === null ? null : now()->parse($validUntil),
    ));
    $pending = $store->requestHodVerification($f['hr'], $f['companyId'], (int) $submitted->id);
    $store->verifyHod($f['hod'], $f['companyId'], (int) $pending->id, 'Baseline verified.');
    $store->finalizeVerified($f['hod'], $f['companyId'], (int) $pending->id);
}

it('shows released history newest-first with the latest highlighted as current', function (): void {
    $this->withoutVite();
    $f = mhFixture();
    $skillId = mhSkill($f, 'safety', 'MH isolation');
    mhRequirement($f, [$skillId]);
    mhFinalized($f, $f['employee'], $skillId, 2, now()->subDays(9)->toDateString());
    mhFinalized($f, $f['employee'], $skillId, 3, now()->subDays(2)->toDateString());

    $page = Livewire::actingAs($f['self'])->test(MySkillHistory::class);
    $page->assertSee('MH isolation')
        ->assertSeeInOrder([
            now()->subDays(2)->format('d M Y'),
            now()->subDays(9)->format('d M Y'),
        ])
        ->assertSee('Current');

    $entries = collect($page->viewData('groups')[0]['entries']);
    expect($entries->where('current', true)->count())->toBe(1)
        ->and($entries->firstWhere('current', true)['level'])->toBe(3);
});

it('shows only the signed-in employee rows', function (): void {
    $this->withoutVite();
    $f = mhFixture();
    $mine = mhSkill($f, 'safety', 'MH isolation');
    $theirs = mhSkill($f, 'peer-craft', 'Peer Craft');
    mhRequirement($f, [$mine, $theirs]);
    mhFinalized($f, $f['employee'], $mine, 2, now()->subDays(3)->toDateString());

    $peer = Employee::factory()->create([
        'company_id' => $f['companyId'], 'department_id' => $f['employee']->department_id,
        'supervisor_id' => $f['employee']->supervisor_id,
        'full_name' => 'History Peer', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    EmployeeWorkProfile::query()->create(['employee_id' => $peer->id, 'organization_unit_id' => $f['unit']->id]);
    mhFinalized($f, $peer, $theirs, 4, now()->subDays(3)->toDateString());

    Livewire::actingAs($f['self'])
        ->test(MySkillHistory::class)
        ->assertSee('MH isolation')
        ->assertDontSee('Peer Craft');
});

it('marks expired scores and never highlights them as current', function (): void {
    $this->withoutVite();
    $f = mhFixture();
    $skillId = mhSkill($f, 'safety', 'MH isolation');
    mhRequirement($f, [$skillId]);
    mhFinalized($f, $f['employee'], $skillId, 2, now()->subDays(30)->toDateString());
    mhFinalized($f, $f['employee'], $skillId, 3, now()->subDays(2)->toDateString(), now()->subDay()->toDateString());

    $page = Livewire::actingAs($f['self'])->test(MySkillHistory::class);
    $page->assertSee('Expired');

    $entries = collect($page->viewData('groups')[0]['entries']);
    expect($entries->where('expired', true)->count())->toBe(1)
        ->and($entries->where('current', true)->count())->toBe(1)
        ->and($entries->firstWhere('current', true)['level'])->toBe(2);
});
