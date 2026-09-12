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
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Livewire\Reassessment\Index as ReassessmentQueue;
use App\Domains\People\Skills\Models\SkillReassessmentRequest;
use App\Domains\People\Skills\Services\SkillAudienceAssignmentStore;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use Livewire\Livewire;

/**
 * 0007-g: the reassessment queue shows what is waiting and how late it is.
 *
 * Requests are written here directly rather than driven through the team-gaps
 * page, because what is under test is the queue, not how a request is raised —
 * that is 0006-b's test and duplicating it would make this file fail for its
 * reasons rather than its own.
 *
 * Self-contained: helpers are prefixed rq and live here.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
    Carbon\Carbon::setTestNow();
});

function rqFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(
        ['name' => 'Queue Tenant'],
        ['name' => 'Queue Company', 'status' => 'active'],
    );
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $unit = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'RQ-OPS', 'name' => 'Queue operations', 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $type = DepartmentType::query()->create([
        'code' => 'rq-ops', 'name' => 'Queue operations', 'category' => 'operational', 'is_active' => true,
    ]);
    $department = Department::query()->create([
        'company_id' => $companyId, 'department_type_id' => $type->id, 'status' => 'active',
    ]);

    $head = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id,
        'full_name' => 'Queue Head', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $department->update(['head_id' => $head->id]);
    EmployeeWorkProfile::query()->create(['employee_id' => $head->id, 'organization_unit_id' => $unit->id]);

    $report = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id, 'supervisor_id' => $head->id,
        'full_name' => 'Queue Report', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    EmployeeWorkProfile::query()->create(['employee_id' => $report->id, 'organization_unit_id' => $unit->id]);

    // Same company, another department, nobody's direct report: the row a head
    // must not see and HR must.
    $outsider = Employee::factory()->create([
        'company_id' => $companyId, 'full_name' => 'Other Department Employee',
        'status' => 'active', 'employee_type' => 'full_time',
    ]);

    $hr = User::factory()->create(['company_id' => $companyId]);
    $hod = User::factory()->create(['company_id' => $companyId, 'employee_id' => $head->id]);
    $nobody = User::factory()->create(['company_id' => $companyId]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $head->id, 'user_id' => $hod->id,
        'display_name' => 'Queue Head', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    foreach ([[$hr, 'people_hr'], [$hod, 'people_hod']] as [$actor, $code]) {
        PrincipalRole::query()->create([
            'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
            'principal_id' => $actor->id,
            'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->valueOrFail('id'),
        ]);
    }

    // The audience resolves a head's reports through an HR-confirmed binding of
    // the platform user to their employee record, not from the user row alone:
    // a user may not claim an identity and inherit its reports. Without this a
    // head sees nothing, which is the correct refusal and not a bug.
    app(SkillAudienceAssignmentStore::class)
        ->confirmActor($hr, $hod, $companyId, (int) $head->id, 'review:reassessment-queue');

    // One category per fixture. defineCategory refuses a duplicate code, so
    // creating it per skill would fail on the second skill in a test.
    $categoryId = (int) app(SkillCatalogStore::class)
        ->defineCategory($companyId, 'rq-safety', 'Queue Safety')->id;

    return compact('tenantId', 'companyId', 'hr', 'hod', 'nobody', 'head',
        'report', 'outsider', 'department', 'categoryId');
}

function rqSkill(array $f, string $code = 'rq.isolation'): int
{
    return (int) app(SkillCatalogStore::class)->defineSkill($f['companyId'], new SkillDraft(
        code: $code, name: 'RQ '.$code, definition: 'Isolate before maintenance.',
        categoryId: $f['categoryId'], defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ))->id;
}

function rqRequest(array $f, Employee $employee, int $skillId, string $dueAt,
    string $status = 'pending', string $source = 'hod'): SkillReassessmentRequest
{
    return SkillReassessmentRequest::query()->create([
        'tenant_id' => $f['tenantId'],
        'company_entity_id' => $f['companyId'],
        'employee_entity_id' => $employee->id,
        'skill_id' => $skillId,
        'reason' => 'Recheck after coaching.',
        'requested_by_user_id' => $f['hod']->id,
        'due_at' => $dueAt,
        'status' => $status,
        'source' => $source,
    ]);
}

it('lists pending requests for the company oldest due first', function (): void {
    $this->withoutVite();
    Carbon\Carbon::setTestNow('2026-06-15 09:00:00');
    $f = rqFixture();
    $skill = rqSkill($f);

    rqRequest($f, $f['report'], $skill, '2026-06-20');
    rqRequest($f, $f['outsider'], rqSkill($f, 'rq.second'), '2026-06-01');

    $rows = Livewire::actingAs($f['hr'])->test(ReassessmentQueue::class)->viewData('rows');

    expect($rows)->toHaveCount(2)
        // Oldest due first: the queue's order is the order of attention.
        ->and($rows[0]['due_at'])->toBe('2026-06-01')
        ->and($rows[1]['due_at'])->toBe('2026-06-20');
});

it('does not call a request overdue on the day it is due', function (): void {
    // The boundary is the whole point. A request due today still has today to
    // be done in, and these date columns carry a time component, so a bare
    // string compare answers the wrong question at exactly this edge.
    $this->withoutVite();
    Carbon\Carbon::setTestNow('2026-06-15 23:30:00');
    $f = rqFixture();

    rqRequest($f, $f['report'], rqSkill($f, 'rq.today'), '2026-06-15');
    rqRequest($f, $f['outsider'], rqSkill($f, 'rq.yesterday'), '2026-06-14');

    $rows = collect(Livewire::actingAs($f['hr'])->test(ReassessmentQueue::class)->viewData('rows'))
        ->keyBy('due_at');

    expect($rows['2026-06-15']['overdue'])->toBeFalse()
        ->and($rows['2026-06-15']['days'])->toBe(0)
        ->and($rows['2026-06-14']['overdue'])->toBeTrue()
        ->and($rows['2026-06-14']['days'])->toBe(1);
});

it('shows a head only their own reports and hides another department', function (): void {
    $this->withoutVite();
    Carbon\Carbon::setTestNow('2026-06-15 09:00:00');
    $f = rqFixture();

    rqRequest($f, $f['report'], rqSkill($f, 'rq.mine'), '2026-06-10');
    rqRequest($f, $f['outsider'], rqSkill($f, 'rq.theirs'), '2026-06-11');

    $hodRows = Livewire::actingAs($f['hod'])->test(ReassessmentQueue::class)->viewData('rows');
    $hrRows = Livewire::actingAs($f['hr'])->test(ReassessmentQueue::class)->viewData('rows');

    expect(array_column($hodRows, 'employee'))->toContain('Queue Report')
        ->and(array_column($hodRows, 'employee'))->not->toContain('Other Department Employee')
        ->and($hrRows)->toHaveCount(2);
});

it('refuses the page to a user without the capability', function (): void {
    $this->withoutVite();
    $f = rqFixture();

    // A signed-in user of the same company holding no Skills role at all.
    $this->actingAs($f['nobody'])
        ->get(route('people.skill.reassessment.index'))
        ->assertForbidden();
});

it('separates a request a head raised from one a training result raised', function (): void {
    $this->withoutVite();
    Carbon\Carbon::setTestNow('2026-06-15 09:00:00');
    $f = rqFixture();

    rqRequest($f, $f['report'], rqSkill($f, 'rq.byhead'), '2026-06-10', source: 'hod');
    rqRequest($f, $f['outsider'], rqSkill($f, 'rq.bytraining'), '2026-06-11', source: 'training');

    $page = Livewire::actingAs($f['hr'])->test(ReassessmentQueue::class);

    expect(array_column($page->viewData('rows'), 'source'))->toContain('hod', 'training');

    $trainingOnly = $page->set('source', 'training')->viewData('rows');
    expect($trainingOnly)->toHaveCount(1)
        ->and($trainingOnly[0]['source'])->toBe('training');
});

it('narrows to overdue requests without hiding the rest by default', function (): void {
    $this->withoutVite();
    Carbon\Carbon::setTestNow('2026-06-15 09:00:00');
    $f = rqFixture();

    rqRequest($f, $f['report'], rqSkill($f, 'rq.late'), '2026-06-01');
    rqRequest($f, $f['outsider'], rqSkill($f, 'rq.soon'), '2026-06-30');
    // Due today, and therefore not late. Without this row the filter's
    // boundary is invisible: '<' and '<=' give the same answer when nothing
    // is due on the day, so the assertion would pass either way.
    rqRequest($f, $f['report'], rqSkill($f, 'rq.today'), '2026-06-15');

    $page = Livewire::actingAs($f['hr'])->test(ReassessmentQueue::class);
    expect($page->viewData('rows'))->toHaveCount(3)
        ->and($page->viewData('overdueCount'))->toBe(1);

    $late = $page->set('overdueOnly', '1')->viewData('rows');
    expect($late)->toHaveCount(1)->and($late[0]['due_at'])->toBe('2026-06-01');
});

it('leaves a resolved request out of the pending queue', function (): void {
    $this->withoutVite();
    Carbon\Carbon::setTestNow('2026-06-15 09:00:00');
    $f = rqFixture();

    rqRequest($f, $f['report'], rqSkill($f, 'rq.open'), '2026-06-10');
    rqRequest($f, $f['outsider'], rqSkill($f, 'rq.done'), '2026-06-11', status: 'resolved');

    $page = Livewire::actingAs($f['hr'])->test(ReassessmentQueue::class);
    expect($page->viewData('rows'))->toHaveCount(1);

    // ...but it is reachable, so a closed request is not simply lost.
    expect($page->set('status', '')->viewData('rows'))->toHaveCount(2);
});
