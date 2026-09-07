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
use App\Domains\People\Skills\Contracts\ResolvesSkillRequirements;
use App\Domains\People\Skills\Data\AssessmentDraft;
use App\Domains\People\Skills\Data\RequirementItemDraft;
use App\Domains\People\Skills\Data\RequirementProfileDraft;
use App\Domains\People\Skills\Data\RequirementSelectorDraft;
use App\Domains\People\Skills\Data\ResolvedSkillRequirement;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentCycle;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\AssessmentStatus;
use App\Domains\People\Skills\Enums\ReassessmentRequestStatus;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Enums\SelectorType;
use App\Domains\People\Skills\Exceptions\InvalidReassessmentRequestException;
use App\Domains\People\Skills\Livewire\TeamGaps\Index as TeamGaps;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Models\SkillReassessmentRequest;
use App\Domains\People\Skills\Services\AssessmentStore;
use App\Domains\People\Skills\Services\RequirementProfileStore;
use App\Domains\People\Skills\Services\SkillAudienceAssignmentStore;
use App\Domains\People\Skills\Services\SkillCatalogDefaults;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Services\SkillReassessmentStore;
use App\Domains\People\Training\Livewire\HrGovernance\Index as HrGovernance;
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

    return compact('tenantId', 'companyId', 'hr', 'hod', 'head', 'report', 'outsider');
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
    rrRequirement($f, $skillId);

    $store = app(AssessmentStore::class);
    $submitted = $store->submit($f['hr'], $f['companyId'], new AssessmentDraft(
        employeeEntityId: (int) $employee->id,
        skillId: $skillId,
        assessedLevel: 2,
        method: AssessmentMethod::DirectObservation,
        cycle: AssessmentCycle::Annual,
        assessedAt: now()->subDays(3),
        evidence: 'Observed task.',
    ));
    $pending = $store->requestHodVerification($f['hr'], $f['companyId'], (int) $submitted->id);
    $store->verifyHod($f['hod'], $f['companyId'], (int) $pending->id, 'Baseline verified.');
    $store->finalizeVerified($f['hod'], $f['companyId'], (int) $pending->id);

    return EmployeeSkillScore::query()->forCompany($f['tenantId'], $f['companyId'])
        ->where('employee_entity_id', $employee->id)->where('skill_id', $skillId)->firstOrFail();
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

    Livewire::actingAs($f['hod'])
        ->test(TeamGaps::class)
        ->set('reasons.'.$f['outsider']->id.'.'.$skillId, 'Sneaky request.')
        ->call('requestReassessment', $f['outsider']->id, $skillId)
        ->assertSee('unavailable in the current scope');

    expect(rrOpenCount($f, $f['outsider'], $skillId))->toBe(0);
});

function rrPendingRequest(array $f, int $skillId): SkillReassessmentRequest
{
    return app(SkillReassessmentStore::class)->request(
        $f['hod'], $f['companyId'], (int) $f['report']->id, $skillId, 'Reassess after retraining.',
    );
}

final class RrRequirements implements ResolvesSkillRequirements
{
    /** @param list<ResolvedSkillRequirement> $rows */
    public function __construct(private array $rows) {}

    public function requirementsFor(array $employeeData, ?DateTimeInterface $asOf = null): array
    {
        return $this->rows;
    }
}

function rrRequirement(array $f, int $skillId): void
{
    app(SkillCatalogDefaults::class)->install($f['companyId']);
    $profiles = app(RequirementProfileStore::class);
    $profile = $profiles->draft($f['companyId'], new RequirementProfileDraft(
        code: 'fixture.rr-ops',
        name: 'RR operations',
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: [new RequirementItemDraft(
            skillId: $skillId,
            sequence: 1,
            requiredLevel: 4,
            criticality: RequirementCriticality::Critical,
            weightPercent: 100.0,
        )],
    ));
    $profile = $profiles->publish($f['companyId'], (int) $profile->id);
    app()->instance(ResolvesSkillRequirements::class, new RrRequirements([
        new ResolvedSkillRequirement(
            requirementReference: 'fixture.rr',
            requirementVersion: 1,
            requirementProfileId: (int) $profile->id,
            skillId: $skillId,
            requiredLevel: 4,
            criticality: RequirementCriticality::Critical,
            mandatoryGate: true,
        ),
    ]));
}

it('performs a pending request with a new finalized assessment and leaves history untouched', function (): void {
    $f = rrFixture();
    $skillId = rrSkill($f);
    $previous = rrScore($f, $f['report'], $skillId);
    $previousSnapshot = SkillAssessment::query()->forCompany($f['tenantId'], $f['companyId'])
        ->whereKey((int) $previous->source_assessment_id)->firstOrFail()->getAttributes();
    $request = rrPendingRequest($f, $skillId);

    $performed = app(SkillReassessmentStore::class)->perform(
        $f['hr'], $f['companyId'], (int) $request->id, 3, now()->toDateString(), 'Retraining complete, observed task again.',
    );

    expect($performed->status)->toBe(ReassessmentRequestStatus::Resolved)
        ->and($performed->performed_by_user_id)->toBe((int) $f['hr']->id)
        ->and($performed->outcome)->toBe('Retraining complete, observed task again.');

    $fresh = EmployeeSkillScore::query()->forCompany($f['tenantId'], $f['companyId'])
        ->where('employee_entity_id', $f['report']->id)->where('skill_id', $skillId)->firstOrFail();
    expect((int) $fresh->current_level)->toBe(3)
        ->and((int) $fresh->source_assessment_id)->not->toBe((int) $previous->source_assessment_id);

    $released = SkillAssessment::query()->forCompany($f['tenantId'], $f['companyId'])
        ->whereKey((int) $fresh->source_assessment_id)->firstOrFail();
    expect($released->status)->toBe(AssessmentStatus::Finalized)
        ->and((int) $released->assessed_level)->toBe(3)
        ->and((int) $released->supersedes_assessment_id)->toBe((int) $previous->source_assessment_id);

    expect(SkillAssessment::query()->forCompany($f['tenantId'], $f['companyId'])
        ->whereKey((int) $previous->source_assessment_id)->firstOrFail()->getAttributes())
        ->toBe($previousSnapshot);
});

it('refuses to perform a closed request again', function (): void {
    $f = rrFixture();
    $skillId = rrSkill($f);
    rrScore($f, $f['report'], $skillId);
    $request = rrPendingRequest($f, $skillId);
    $store = app(SkillReassessmentStore::class);
    $store->perform($f['hr'], $f['companyId'], (int) $request->id, 3, now()->toDateString(), 'First pass.');

    expect(fn () => $store->perform($f['hr'], $f['companyId'], (int) $request->id, 4, now()->toDateString(), 'Second pass.'))
        ->toThrow(InvalidReassessmentRequestException::class);
});

it('refuses execute without the execute capability', function (): void {
    $f = rrFixture();
    $skillId = rrSkill($f);
    rrScore($f, $f['report'], $skillId);
    $request = rrPendingRequest($f, $skillId);

    expect(fn () => app(SkillReassessmentStore::class)->perform(
        $f['hod'], $f['companyId'], (int) $request->id, 3, now()->toDateString(), 'HOD self-perform.',
    ))->toThrow(AuthorizationDeniedException::class);
});

it('performs a reassessment from the HR governance queue', function (): void {
    $this->withoutVite();
    $f = rrFixture();
    $skillId = rrSkill($f);
    rrScore($f, $f['report'], $skillId);
    $request = rrPendingRequest($f, $skillId);

    Livewire::actingAs($f['hr'])
        ->test(HrGovernance::class)
        ->assertSee('Reassess after retraining.')
        ->assertSee('Reassessment Report')
        ->set('reassessmentLevels.'.$request->id, 3)
        ->set('reassessmentDates.'.$request->id, now()->toDateString())
        ->set('reassessmentNotes.'.$request->id, 'Queue-recorded outcome.')
        ->call('performReassessment', $request->id)
        ->assertSee('No skill reassessment awaits HR decision.')
        ->assertDontSee('Reassess after retraining.');

    expect($request->refresh()->status)->toBe(ReassessmentRequestStatus::Resolved);
});

it('refuses a sibling-company queue and perform at the attribution boundary', function (): void {
    $f = rrFixture();
    $skillId = rrSkill($f);
    rrScore($f, $f['report'], $skillId);
    $request = rrPendingRequest($f, $skillId);
    $store = app(SkillReassessmentStore::class);

    $sibling = Company::factory()->create(['tenant_id' => $f['tenantId'], 'name' => 'Sibling reassessment co', 'status' => 'active']);

    expect($store->pendingQueue($f['hr'], $f['companyId'])->pluck('id')->all())->toBe([(int) $request->id]);

    expect(fn () => $store->pendingQueue($f['hr'], (int) $sibling->id))
        ->toThrow(InvalidReassessmentRequestException::class);

    expect(fn () => $store->perform($f['hr'], (int) $sibling->id, (int) $request->id, 3, now()->toDateString(), 'Cross-company.'))
        ->toThrow(InvalidReassessmentRequestException::class);
});
