<?php

use App\Base\Audit\Models\AuditMutation;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Attendance\Livewire\AllowanceRules;
use App\Domains\People\Attendance\Livewire\Approvals;
use App\Domains\People\Attendance\Livewire\MyAttendance;
use App\Domains\People\Attendance\Livewire\Operations;
use App\Domains\People\Attendance\Livewire\PolicyGroups;
use App\Domains\People\Attendance\Livewire\Rosters;
use App\Domains\People\Attendance\Livewire\ShiftTemplates;
use App\Domains\People\Attendance\Models\AttendanceAllowanceRule;
use App\Domains\People\Attendance\Models\AttendanceClockEvent;
use App\Domains\People\Attendance\Models\AttendanceDay;
use App\Domains\People\Attendance\Models\AttendanceOvertimeRequest;
use App\Domains\People\Attendance\Models\AttendancePolicyGroup;
use App\Domains\People\Attendance\Models\AttendanceRosterAssignment;
use App\Domains\People\Attendance\Models\AttendanceRosterLock;
use App\Domains\People\Attendance\Models\AttendanceShiftTemplate;
use App\Domains\People\Payroll\Models\PayrollCalendar;
use App\Domains\People\Payroll\Models\PayrollInput;
use App\Domains\People\Payroll\Models\PayrollPeriod;
use App\Domains\People\Payroll\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Coverage for Attendance Livewire actions that no other test calls
|--------------------------------------------------------------------------
|
| Every helper below is local to this file so the file runs alone with
| `vendor/bin/pest <file>`. Only `createAdminUser()` from the platform's
| tests/Pest.php bootstrap is shared, because it is the bootstrap.
*/

const COVERAGE_DAY_DATE = '2026-05-13';

/** @return array{0: User, 1: Company} */
function coverageAdmin(): array
{
    $user = createAdminUser();

    return [$user, Company::query()->findOrFail($user->company_id)];
}

/**
 * A user in the same company whose only Attendance capability is `view`, so
 * every `manage`, `approve` and `execute` guard denies them.
 */
function coverageViewOnlyUser(Company $company, ?Employee $employee = null): User
{
    $role = Role::query()->create([
        'company_id' => $company->id,
        'code' => 'attendance_view_only_'.$company->id,
        'name' => 'Attendance View Only',
        'is_system' => false,
        'grant_all' => false,
    ]);

    DB::table('base_authz_role_capabilities')->insert([
        'role_id' => $role->id,
        'capability_key' => 'people.attendance.view',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee?->id,
    ]);

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);

    return $user;
}

/** @param array<string, mixed> $attributes */
function coverageShift(Company $company, array $attributes = []): AttendanceShiftTemplate
{
    return AttendanceShiftTemplate::query()->create(array_replace([
        'company_id' => $company->id,
        'code' => 'DAY',
        'name' => 'Day Shift',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ], $attributes));
}

/** @param array<string, mixed> $attributes */
function coveragePolicy(Company $company, array $attributes = []): AttendancePolicyGroup
{
    return AttendancePolicyGroup::query()->create(array_replace([
        'company_id' => $company->id,
        'code' => 'STD',
        'name' => 'Standard',
        'effective_from' => '2026-01-01',
        'status' => AttendancePolicyGroup::STATUS_ACTIVE,
    ], $attributes));
}

/** @param array<string, mixed> $attributes */
function coverageAssignment(
    Company $company,
    Employee $employee,
    AttendanceShiftTemplate $shift,
    AttendancePolicyGroup $policy,
    array $attributes = [],
): AttendanceRosterAssignment {
    return AttendanceRosterAssignment::query()->create(array_replace([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_shift_template_id' => $shift->id,
        'attendance_policy_group_id' => $policy->id,
        'effective_from' => '2026-09-01',
        'effective_to' => '2026-09-01',
        'publish_state' => 'published',
        'lock_state' => 'open',
        'revision' => 1,
        'exceptions' => [],
        'metadata' => [],
    ], $attributes));
}

/** @param array<string, mixed> $attributes */
function coverageDay(Company $company, Employee $employee, array $attributes = []): AttendanceDay
{
    return AttendanceDay::query()->create(array_replace([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_date' => COVERAGE_DAY_DATE,
        'expected_minutes' => 480,
        'payroll_period_date' => COVERAGE_DAY_DATE,
        'status' => AttendanceDay::STATUS_READY_FOR_REVIEW,
    ], $attributes));
}

/** @param array<string, mixed> $attributes */
function coverageOvertimeRequest(Company $company, Employee $employee, array $attributes = []): AttendanceOvertimeRequest
{
    return AttendanceOvertimeRequest::query()->create(array_replace([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'status' => AttendanceOvertimeRequest::STATUS_SUBMITTED,
        'starts_at' => '2026-05-13 17:00:00',
        'ends_at' => '2026-05-13 19:00:00',
        'requested_minutes' => 120,
        'reason' => 'Production support',
        'policy_snapshot' => ['overtime_pay_item_code' => 'OT15'],
    ], $attributes));
}

/** @param array<string, mixed> $attributes */
function coverageAllowanceRule(Company $company, AttendancePolicyGroup $policy, array $attributes = []): AttendanceAllowanceRule
{
    return AttendanceAllowanceRule::query()->create(array_replace([
        'company_id' => $company->id,
        'attendance_policy_group_id' => $policy->id,
        'code' => 'MEAL',
        'name' => 'Meal allowance',
        'allowance_type' => AttendanceAllowanceRule::TYPE_DAILY,
        'resolution_method' => AttendanceAllowanceRule::RESOLUTION_SUM,
        'condition_rows' => [['amount' => 10, 'predicate' => []]],
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ], $attributes));
}

function coveragePayrollRun(Company $company): PayrollRun
{
    $calendar = PayrollCalendar::query()->create([
        'company_id' => $company->id,
        'code' => 'MONTHLY',
        'name' => 'Monthly',
        'country_iso' => 'MY',
        'currency' => 'MYR',
        'frequency' => 'monthly',
    ]);
    $period = PayrollPeriod::query()->create([
        'payroll_calendar_id' => $calendar->id,
        'code' => '2026-05',
        'name' => 'May 2026',
        'starts_on' => '2026-05-01',
        'ends_on' => '2026-05-31',
        'pay_date' => '2026-05-31',
    ]);

    return PayrollRun::query()->create([
        'company_id' => $company->id,
        'payroll_calendar_id' => $calendar->id,
        'payroll_period_id' => $period->id,
        'code' => 'MAY-2026',
        'name' => 'May 2026',
        'status' => PayrollRun::STATUS_DRAFT,
        'currency' => 'MYR',
    ]);
}

/** @return array{0: string, 1: string} Monday and Sunday of the current week. */
function coverageThisWeek(): array
{
    return [
        CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
        CarbonImmutable::today()->endOfWeek(CarbonImmutable::SUNDAY)->toDateString(),
    ];
}

function coverageNotified(string $fragment, string $variant = 'success'): Closure
{
    return fn (string $event, array $params): bool => str_contains((string) ($params['message'] ?? ''), $fragment)
        && ($params['variant'] ?? null) === $variant;
}

// ─── Approvals ────────────────────────────────────────────────────────────────

it('approves a submitted overtime request from the approvals workbench', function (): void {
    [$user, $company] = coverageAdmin();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $request = coverageOvertimeRequest($company, $employee);

    $this->actingAs($user);

    Livewire::test(Approvals::class)
        ->set('decisionReason', 'Month-end support')
        ->call('approveOvertime', $request->id)
        ->assertHasNoErrors()
        ->assertSet('decisionReason', '')
        ->assertDispatched('notify', coverageNotified('Overtime request approved.'));

    $request->refresh();

    expect($request->status)->toBe(AttendanceOvertimeRequest::STATUS_APPROVED)
        ->and($request->approved_minutes)->toBe(120)
        ->and($request->decision_reason)->toBe('Month-end support');
});

it('denies approveOvertime without the approve capability and across companies', function (): void {
    [$user, $company] = coverageAdmin();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $request = coverageOvertimeRequest($company, $employee);
    $otherCompany = Company::factory()->minimal()->create();
    $otherEmployee = Employee::factory()->active()->create(['company_id' => $otherCompany->id]);
    $foreign = coverageOvertimeRequest($otherCompany, $otherEmployee);

    $this->actingAs(coverageViewOnlyUser($company));
    expect(fn () => Livewire::test(Approvals::class)->call('approveOvertime', $request->id))
        ->toThrow(AuthorizationDeniedException::class);

    $this->actingAs($user);
    expect(fn () => Livewire::test(Approvals::class)->call('approveOvertime', $foreign->id))
        ->toThrow(ModelNotFoundException::class);

    expect($request->refresh()->status)->toBe(AttendanceOvertimeRequest::STATUS_SUBMITTED)
        ->and($foreign->refresh()->status)->toBe(AttendanceOvertimeRequest::STATUS_SUBMITTED);
});

it('rejects a submitted overtime request and records the decision reason', function (): void {
    [$user, $company] = coverageAdmin();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $request = coverageOvertimeRequest($company, $employee);

    $this->actingAs($user);

    Livewire::test(Approvals::class)
        ->set('decisionReason', 'Not pre-approved')
        ->call('rejectOvertime', $request->id)
        ->assertHasNoErrors()
        ->assertSet('decisionReason', '')
        ->assertDispatched('notify', coverageNotified('Overtime request rejected.'));

    expect($request->refresh()->status)->toBe(AttendanceOvertimeRequest::STATUS_REJECTED)
        ->and($request->decision_reason)->toBe('Not pre-approved');
});

it('denies rejectOvertime without the approve capability', function (): void {
    [, $company] = coverageAdmin();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $request = coverageOvertimeRequest($company, $employee);

    $this->actingAs(coverageViewOnlyUser($company));

    expect(fn () => Livewire::test(Approvals::class)->call('rejectOvertime', $request->id))
        ->toThrow(AuthorizationDeniedException::class);

    expect($request->refresh()->status)->toBe(AttendanceOvertimeRequest::STATUS_SUBMITTED);
});

it('queues an approved overtime request to payroll from the approvals workbench', function (): void {
    [$user, $company] = coverageAdmin();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $day = coverageDay($company, $employee);
    $request = coverageOvertimeRequest($company, $employee, [
        'attendance_day_id' => $day->id,
        'status' => AttendanceOvertimeRequest::STATUS_APPROVED,
        'approved_minutes' => 90,
        'payable_minutes' => 90,
        'approved_at' => now(),
    ]);
    coveragePayrollRun($company);

    $this->actingAs($user);

    Livewire::test(Approvals::class)
        ->call('queueOvertimePayroll', $request->id)
        ->assertHasNoErrors()
        ->assertDispatched('notify', coverageNotified('Overtime queued to payroll.'));

    expect($request->refresh()->status)->toBe(AttendanceOvertimeRequest::STATUS_QUEUED_FOR_PAYROLL)
        ->and(PayrollInput::query()->count())->toBe(1);
});

it('refuses to queue overtime with no payable minutes and denies the action to non-approvers', function (): void {
    [$user, $company] = coverageAdmin();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $request = coverageOvertimeRequest($company, $employee, [
        'status' => AttendanceOvertimeRequest::STATUS_APPROVED,
        'approved_minutes' => 0,
        'payable_minutes' => 0,
        'approved_at' => now(),
    ]);

    $this->actingAs($user);

    Livewire::test(Approvals::class)
        ->call('queueOvertimePayroll', $request->id)
        ->assertDispatched('notify', coverageNotified('No payable minutes', 'error'));

    expect($request->refresh()->status)->toBe(AttendanceOvertimeRequest::STATUS_APPROVED);

    $this->actingAs(coverageViewOnlyUser($company));

    expect(fn () => Livewire::test(Approvals::class)->call('queueOvertimePayroll', $request->id))
        ->toThrow(AuthorizationDeniedException::class);
});

// ─── Operations ───────────────────────────────────────────────────────────────

it('finalizes a reviewable attendance day from the operations screen', function (): void {
    [$user, $company] = coverageAdmin();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $day = coverageDay($company, $employee);

    $this->actingAs($user);

    Livewire::test(Operations::class)
        ->call('finalizeDay', $day->id)
        ->assertHasNoErrors()
        ->assertDispatched('notify', coverageNotified('Attendance day finalized.'));

    expect($day->refresh()->status)->toBe(AttendanceDay::STATUS_FINALIZED)
        ->and($day->finalized_at)->not->toBeNull();
});

it('refuses to finalize a scheduled day, a foreign day, or for a non-manager', function (): void {
    [$user, $company] = coverageAdmin();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $scheduled = coverageDay($company, $employee, ['status' => AttendanceDay::STATUS_SCHEDULED]);
    $otherCompany = Company::factory()->minimal()->create();
    $otherEmployee = Employee::factory()->active()->create(['company_id' => $otherCompany->id]);
    $foreign = coverageDay($otherCompany, $otherEmployee);

    $this->actingAs($user);

    // AttendanceLifecycleException is a RuntimeException, not a BlbException, so
    // the platform's RecoverFromActionFailure hook turns it into an error toast
    // instead of letting it reach the test; the observable refusal is the toast
    // plus the unchanged status.
    Livewire::test(Operations::class)
        ->call('finalizeDay', $scheduled->id)
        ->assertDispatched('notify', coverageNotified('did not finish', 'error'))
        ->assertNotDispatched('notify', coverageNotified('Attendance day finalized.'));
    expect(fn () => Livewire::test(Operations::class)->call('finalizeDay', $foreign->id))
        ->toThrow(ModelNotFoundException::class);

    $this->actingAs(coverageViewOnlyUser($company));

    expect(fn () => Livewire::test(Operations::class)->call('finalizeDay', $scheduled->id))
        ->toThrow(AuthorizationDeniedException::class);

    expect($scheduled->refresh()->status)->toBe(AttendanceDay::STATUS_SCHEDULED)
        ->and($foreign->refresh()->status)->toBe(AttendanceDay::STATUS_READY_FOR_REVIEW);
});

it('locks an attendance day from the operations screen', function (): void {
    [$user, $company] = coverageAdmin();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $day = coverageDay($company, $employee, ['status' => AttendanceDay::STATUS_FINALIZED]);

    $this->actingAs($user);

    Livewire::test(Operations::class)
        ->call('lockDay', $day->id)
        ->assertHasNoErrors()
        ->assertDispatched('notify', coverageNotified('Attendance day locked.'));

    expect($day->refresh()->status)->toBe(AttendanceDay::STATUS_LOCKED)
        ->and($day->locked_at)->not->toBeNull();
});

it('denies lockDay without the manage capability', function (): void {
    [, $company] = coverageAdmin();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $day = coverageDay($company, $employee, ['status' => AttendanceDay::STATUS_FINALIZED]);

    $this->actingAs(coverageViewOnlyUser($company));

    expect(fn () => Livewire::test(Operations::class)->call('lockDay', $day->id))
        ->toThrow(AuthorizationDeniedException::class);

    expect($day->refresh()->locked_at)->toBeNull();
});

// ─── MyAttendance ─────────────────────────────────────────────────────────────

it('records a web clock-in for the linked employee', function (): void {
    [$user, $company] = coverageAdmin();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $user->forceFill(['employee_id' => $employee->id])->save();

    $this->actingAs($user);

    Livewire::test(MyAttendance::class)
        ->call('clock', AttendanceClockEvent::TYPE_IN)
        ->assertHasNoErrors()
        ->assertDispatched('notify', coverageNotified('Clock event recorded.'));

    $event = AttendanceClockEvent::query()->where('employee_id', $employee->id)->first();

    expect($event)->not->toBeNull()
        ->and($event?->event_type)->toBe(AttendanceClockEvent::TYPE_IN)
        ->and($event?->source)->toBe(AttendanceClockEvent::SOURCE_WEB);
});

it('ignores unknown clock event types, explains an unlinked account, and denies users without execute', function (): void {
    [$user, $company] = coverageAdmin();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);

    $this->actingAs($user);

    Livewire::test(MyAttendance::class)
        ->call('clock', 'lunch')
        ->assertHasNoErrors()
        ->assertNotDispatched('notify');

    Livewire::test(MyAttendance::class)
        ->call('clock', AttendanceClockEvent::TYPE_IN)
        ->assertDispatched('notify', coverageNotified('not linked to an employee record', 'error'));

    $this->actingAs(coverageViewOnlyUser($company, $employee));

    expect(fn () => Livewire::test(MyAttendance::class)->call('clock', AttendanceClockEvent::TYPE_IN))
        ->toThrow(AuthorizationDeniedException::class);

    expect(AttendanceClockEvent::query()->where('company_id', $company->id)->count())->toBe(0);
});

// ─── Rosters: period operations ───────────────────────────────────────────────

it('copies the previous period roster for the selected employees as a draft operation', function (): void {
    [$user, $company] = coverageAdmin();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $shift = coverageShift($company);
    $policy = coveragePolicy($company);
    [$monday, $sunday] = coverageThisWeek();
    $previous = coverageAssignment($company, $employee, $shift, $policy, [
        'effective_from' => CarbonImmutable::parse($monday)->subDays(7)->toDateString(),
        'effective_to' => CarbonImmutable::parse($sunday)->subDays(7)->toDateString(),
    ]);

    $this->actingAs($user);

    $component = Livewire::test(Rosters::class)
        ->set('rosterEffectiveFrom', $monday)
        ->set('rosterEffectiveTo', $sunday)
        ->set('selectedRosterEmployeeIds', [(string) $employee->id])
        ->call('copyPreviousPeriod')
        ->assertHasNoErrors()
        ->assertDispatched('notify', coverageNotified('Copied 1 roster assignment'));

    $copy = AttendanceRosterAssignment::query()
        ->where('employee_id', $employee->id)
        ->whereDate('effective_from', $monday)
        ->first();

    expect($copy)->not->toBeNull()
        ->and($copy?->effective_to?->toDateString())->toBe($sunday)
        ->and($copy?->metadata['copied_from_assignment_id'] ?? null)->toBe($previous->id)
        ->and($copy?->lock_state)->toBe('open');

    $component->assertSet('lastDraftAssignmentIds', [$copy?->id]);
});

it('refuses copyPreviousPeriod without a selection and denies it to non-managers', function (): void {
    [$user, $company] = coverageAdmin();

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->call('copyPreviousPeriod')
        ->assertHasErrors(['selectedRosterEmployeeIds' => 'Select employees before copying a previous period.']);

    $this->actingAs(coverageViewOnlyUser($company));

    expect(fn () => Livewire::test(Rosters::class)->call('copyPreviousPeriod'))
        ->toThrow(AuthorizationDeniedException::class);
});

it('undoes the last draft roster operation and refuses when there is nothing to undo', function (): void {
    [$user, $company] = coverageAdmin();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $shift = coverageShift($company);
    $policy = coveragePolicy($company);
    $draft = coverageAssignment($company, $employee, $shift, $policy);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->set('lastDraftAssignmentIds', [$draft->id])
        ->call('undoLastDraftRosterOperation')
        ->assertHasNoErrors()
        ->assertSet('lastDraftAssignmentIds', [])
        ->assertDispatched('notify', coverageNotified('Undid 1 draft roster assignment'));

    expect(AttendanceRosterAssignment::query()->whereKey($draft->id)->exists())->toBeFalse();

    Livewire::test(Rosters::class)
        ->call('undoLastDraftRosterOperation')
        ->assertHasErrors(['selectedRosterEmployeeIds' => 'There is no draft roster operation to undo.']);
});

it('scopes undoLastDraftRosterOperation to the acting company', function (): void {
    [$user] = coverageAdmin();
    $otherCompany = Company::factory()->minimal()->create();
    $otherEmployee = Employee::factory()->active()->create(['company_id' => $otherCompany->id]);
    $foreign = coverageAssignment($otherCompany, $otherEmployee, coverageShift($otherCompany), coveragePolicy($otherCompany));

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->set('lastDraftAssignmentIds', [$foreign->id])
        ->call('undoLastDraftRosterOperation')
        ->assertDispatched('notify', coverageNotified('Undid 0 draft roster assignments'));

    expect(AttendanceRosterAssignment::query()->whereKey($foreign->id)->exists())->toBeTrue();
});

it('swaps the shifts of two employees on a date as exception overrides', function (): void {
    [$user, $company] = coverageAdmin();
    $first = Employee::factory()->active()->create(['company_id' => $company->id]);
    $second = Employee::factory()->active()->create(['company_id' => $company->id]);
    $dayShift = coverageShift($company);
    $nightShift = coverageShift($company, ['code' => 'NIGHT', 'name' => 'Night Shift', 'starts_at' => '20:00:00', 'ends_at' => '05:00:00']);
    $policy = coveragePolicy($company);
    $firstAssignment = coverageAssignment($company, $first, $dayShift, $policy);
    $secondAssignment = coverageAssignment($company, $second, $nightShift, $policy);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->set('swapFromEmployeeId', (string) $first->id)
        ->set('swapToEmployeeId', (string) $second->id)
        ->set('swapDate', '2026-09-01')
        ->call('swapRosterCells')
        ->assertHasNoErrors()
        ->assertDispatched('close-swap-modal')
        ->assertDispatched('notify', coverageNotified('Roster shift swap saved.'));

    $firstOverride = collect($firstAssignment->refresh()->exceptions)->firstWhere('date', '2026-09-01');
    $secondOverride = collect($secondAssignment->refresh()->exceptions)->firstWhere('date', '2026-09-01');

    expect($firstOverride['attendance_shift_template_id'] ?? null)->toBe($nightShift->id)
        ->and($firstOverride['source'] ?? null)->toBe('swap')
        ->and($secondOverride['attendance_shift_template_id'] ?? null)->toBe($dayShift->id);
});

it('refuses swapRosterCells for blank input, locked dates, missing assignments, and non-managers', function (): void {
    [$user, $company] = coverageAdmin();
    $first = Employee::factory()->active()->create(['company_id' => $company->id]);
    $second = Employee::factory()->active()->create(['company_id' => $company->id]);
    $shift = coverageShift($company);
    $policy = coveragePolicy($company);
    $assignment = coverageAssignment($company, $first, $shift, $policy);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->call('swapRosterCells')
        ->assertHasErrors(['swapDate' => 'Choose two employees and a date to swap.']);

    Livewire::test(Rosters::class)
        ->set('swapFromEmployeeId', (string) $first->id)
        ->set('swapToEmployeeId', (string) $second->id)
        ->set('swapDate', '2026-09-01')
        ->call('swapRosterCells')
        ->assertHasErrors(['swapDate' => 'Both employees need an assignment on the selected date before swapping.'])
        ->assertNotDispatched('close-swap-modal');

    AttendanceRosterLock::query()->create([
        'company_id' => $company->id,
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-07',
        'locked_by' => $user->id,
        'locked_at' => now(),
    ]);
    coverageAssignment($company, $second, $shift, $policy);

    Livewire::test(Rosters::class)
        ->set('swapFromEmployeeId', (string) $first->id)
        ->set('swapToEmployeeId', (string) $second->id)
        ->set('swapDate', '2026-09-01')
        ->call('swapRosterCells')
        ->assertHasErrors(['swapDate' => 'This date is in a locked roster period and cannot be swapped.']);

    expect($assignment->refresh()->exceptions)->toBeEmpty();

    $this->actingAs(coverageViewOnlyUser($company));

    expect(fn () => Livewire::test(Rosters::class)->call('swapRosterCells'))
        ->toThrow(AuthorizationDeniedException::class);
});

it('deletes a roster assignment and refuses foreign or unauthorized deletes', function (): void {
    [$user, $company] = coverageAdmin();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $assignment = coverageAssignment($company, $employee, coverageShift($company), coveragePolicy($company));
    $otherCompany = Company::factory()->minimal()->create();
    $otherEmployee = Employee::factory()->active()->create(['company_id' => $otherCompany->id]);
    $foreign = coverageAssignment($otherCompany, $otherEmployee, coverageShift($otherCompany), coveragePolicy($otherCompany));

    $this->actingAs($user);

    expect(fn () => Livewire::test(Rosters::class)->call('deleteRosterAssignment', $foreign->id))
        ->toThrow(ModelNotFoundException::class);

    $this->actingAs(coverageViewOnlyUser($company));

    expect(fn () => Livewire::test(Rosters::class)->call('deleteRosterAssignment', $assignment->id))
        ->toThrow(AuthorizationDeniedException::class);

    expect(AttendanceRosterAssignment::query()->whereKey($assignment->id)->exists())->toBeTrue()
        ->and(AttendanceRosterAssignment::query()->whereKey($foreign->id)->exists())->toBeTrue();

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->call('deleteRosterAssignment', $assignment->id)
        ->assertHasNoErrors()
        ->assertDispatched('notify', coverageNotified('Roster assignment deleted.'));

    expect(AttendanceRosterAssignment::query()->whereKey($assignment->id)->exists())->toBeFalse();
});

// ─── Rosters: cell history, export, selection ─────────────────────────────────

it('loads and closes the audit history of a roster cell', function (): void {
    [$user, $company] = coverageAdmin();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);

    // The expanded roster audit row is written by the audit subject index
    // (covered by RosterAuditSubjectTest); here it is seeded directly with the
    // company and actor the history query filters on.
    $assignment = coverageAssignment($company, $employee, coverageShift($company), coveragePolicy($company));
    AuditMutation::query()->create([
        'company_id' => $company->id,
        'actor_type' => PrincipalType::USER->value,
        'actor_id' => $user->id,
        'auditable_type' => AttendanceRosterAssignment::class,
        'auditable_id' => (string) $assignment->id,
        'subject_name' => 'employee',
        'subject_id' => (string) $employee->id,
        'subject_identifier' => '2026-09-01',
        'source' => 'expanded',
        'event' => 'created',
        'old_values' => null,
        'new_values' => ['shift_code' => 'DAY', 'policy_code' => 'STD'],
        'occurred_at' => now(),
    ]);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->call('loadCellHistory', $employee->id, '2026-09-01')
        ->assertHasNoErrors()
        ->assertSet('cellHistoryOpen', true)
        ->assertSet('cellHistoryEmployeeId', $employee->id)
        ->assertSet('cellHistoryDate', '2026-09-01')
        ->assertSet('cellHistoryRows.0.new_shift', 'DAY')
        ->assertSet('cellHistoryRows.0.new_policy', 'STD')
        ->assertSet('cellHistoryRows.0.changed_by', $user->name)
        ->call('closeCellHistory')
        ->assertSet('cellHistoryOpen', false)
        ->assertSet('cellHistoryRows', [])
        ->assertSet('cellHistoryEmployeeId', 0)
        ->assertSet('cellHistoryDate', '')
        ->assertSet('cellHistoryEmployeeName', '');
});

it('keeps cell history closed for a foreign employee and denies it to non-managers', function (): void {
    [$user, $company] = coverageAdmin();
    $otherCompany = Company::factory()->minimal()->create();
    $otherEmployee = Employee::factory()->active()->create(['company_id' => $otherCompany->id]);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->call('loadCellHistory', $otherEmployee->id, '2026-09-01')
        ->assertHasNoErrors()
        ->assertSet('cellHistoryOpen', false)
        ->assertSet('cellHistoryRows', []);

    $this->actingAs(coverageViewOnlyUser($company));

    expect(fn () => Livewire::test(Rosters::class)->call('loadCellHistory', $otherEmployee->id, '2026-09-01'))
        ->toThrow(AuthorizationDeniedException::class);
});

it('streams the roster grid as a CSV download for managers only', function (): void {
    [$user, $company] = coverageAdmin();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    [$monday, $sunday] = coverageThisWeek();
    coverageAssignment($company, $employee, coverageShift($company), coveragePolicy($company), [
        'effective_from' => $monday,
        'effective_to' => $sunday,
    ]);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->set('rosterEffectiveFrom', $monday)
        ->set('rosterEffectiveTo', $sunday)
        ->call('exportRosterCsv')
        ->assertHasNoErrors()
        ->assertFileDownloaded('roster-'.$monday.'.csv');

    $this->actingAs(coverageViewOnlyUser($company));

    expect(fn () => Livewire::test(Rosters::class)->call('exportRosterCsv'))
        ->toThrow(AuthorizationDeniedException::class);
});

it('selects the visible roster employees of the acting company and clears the selection', function (): void {
    [$user, $company] = coverageAdmin();
    $first = Employee::factory()->active()->create(['company_id' => $company->id, 'full_name' => 'Alpha Visible']);
    $second = Employee::factory()->active()->create(['company_id' => $company->id, 'full_name' => 'Beta Visible']);
    $otherCompany = Company::factory()->minimal()->create();
    $foreign = Employee::factory()->active()->create(['company_id' => $otherCompany->id, 'full_name' => 'Gamma Foreign']);

    $this->actingAs($user);

    $component = Livewire::test(Rosters::class)
        ->call('selectVisibleRosterEmployees')
        ->assertHasNoErrors();

    $selected = $component->get('selectedRosterEmployeeIds');

    expect($selected)->toContain((string) $first->id, (string) $second->id)
        ->and($selected)->not->toContain((string) $foreign->id);

    $component
        ->set('rosterSelectAllFiltered', true)
        ->set('rosterEmployeeId', (string) $first->id)
        ->call('clearRosterSelection')
        ->assertSet('rosterSelectAllFiltered', false)
        ->assertSet('selectedRosterEmployeeIds', [])
        ->assertSet('rosterEmployeeId', '');
});

// ─── ShiftTemplates ───────────────────────────────────────────────────────────

it('loads a shift template into the builder, then cancels without persisting edits', function (): void {
    [$user, $company] = coverageAdmin();
    $shift = coverageShift($company, ['break_windows' => [['label' => 'Lunch', 'starts_at' => '12:00', 'ends_at' => '13:00', 'paid' => false]]]);

    $this->actingAs($user);

    Livewire::test(ShiftTemplates::class)
        ->call('editShiftTemplate', $shift->id)
        ->assertHasNoErrors()
        ->assertSet('mode', 'form')
        ->assertSet('editingShiftTemplateId', $shift->id)
        ->assertSet('shiftCode', 'DAY')
        ->assertSet('shiftName', 'Day Shift')
        ->assertSet('shiftStartsAt', '08:00')
        ->assertSet('shiftEndsAt', '17:00')
        ->assertSet('shiftExpectedWorkMinutes', '480')
        ->assertSet('shiftBreaks.0.label', 'Lunch')
        ->assertSet('showShiftBuilderForm', true)
        ->assertSet('showAllShiftTemplates', false)
        ->set('shiftName', 'Renamed but abandoned')
        ->call('cancelShiftEdit')
        ->assertSet('mode', 'list')
        ->assertSet('editingShiftTemplateId', null)
        ->assertSet('shiftName', '')
        ->assertSet('shiftBreaks', [])
        ->assertSet('showShiftBuilderForm', false)
        ->assertSet('showAllShiftTemplates', true);

    expect($shift->refresh()->name)->toBe('Day Shift');
});

it('refuses editShiftTemplate for foreign templates and non-managers', function (): void {
    [$user, $company] = coverageAdmin();
    $shift = coverageShift($company);
    $otherCompany = Company::factory()->minimal()->create();
    $foreign = coverageShift($otherCompany);

    $this->actingAs($user);

    expect(fn () => Livewire::test(ShiftTemplates::class)->call('editShiftTemplate', $foreign->id))
        ->toThrow(ModelNotFoundException::class);

    $this->actingAs(coverageViewOnlyUser($company));

    expect(fn () => Livewire::test(ShiftTemplates::class)->call('editShiftTemplate', $shift->id))
        ->toThrow(AuthorizationDeniedException::class);
});

it('duplicates a shift template into an inactive unsaved copy with a unique code', function (): void {
    [$user, $company] = coverageAdmin();
    $shift = coverageShift($company);
    coverageShift($company, ['code' => 'DAY_COPY', 'name' => 'Taken']);

    $this->actingAs($user);

    Livewire::test(ShiftTemplates::class)
        ->call('duplicateShiftTemplate', $shift->id)
        ->assertHasNoErrors()
        ->assertSet('mode', 'form')
        ->assertSet('editingShiftTemplateId', null)
        ->assertSet('shiftCode', 'DAY_COPY_2')
        ->assertSet('shiftName', 'Day Shift Copy')
        ->assertSet('shiftStatus', 'inactive')
        ->assertSet('shiftStartsAt', '08:00');

    expect(AttendanceShiftTemplate::query()->where('company_id', $company->id)->count())->toBe(2);

    $this->actingAs(coverageViewOnlyUser($company));

    expect(fn () => Livewire::test(ShiftTemplates::class)->call('duplicateShiftTemplate', $shift->id))
        ->toThrow(AuthorizationDeniedException::class);
});

it('prepares a shift template JSON export for managers only', function (): void {
    [$user, $company] = coverageAdmin();
    $shift = coverageShift($company);

    $this->actingAs($user);

    $component = Livewire::test(ShiftTemplates::class)
        ->call('exportShiftTemplate', $shift->id)
        ->assertHasNoErrors()
        ->assertDispatched('notify', coverageNotified('Shift template JSON ready to download from DAY.'));

    $payload = json_decode((string) $component->get('shiftTemplateExportJson'), true);

    expect($payload['code'] ?? null)->toBe('DAY')
        ->and($payload['name'] ?? null)->toBe('Day Shift');

    $this->actingAs(coverageViewOnlyUser($company));

    expect(fn () => Livewire::test(ShiftTemplates::class)->call('exportShiftTemplate', $shift->id))
        ->toThrow(AuthorizationDeniedException::class);
});

it('applies a guided shift preset, ignores unknown presets, and collapses on a second pick', function (): void {
    [$user] = coverageAdmin();

    $this->actingAs($user);

    Livewire::test(ShiftTemplates::class)
        ->call('startNewShift')
        ->assertSet('mode', 'form')
        ->assertSet('showAllShiftTemplates', true)
        ->call('useShiftTemplate', 'no-such-preset')
        ->assertSet('shiftCode', '')
        ->assertSet('showShiftBuilderForm', false)
        ->assertNotDispatched('notify')
        ->call('useShiftTemplate', 'night-shift')
        ->assertSet('shiftCode', 'NIGHT_SHIFT')
        ->assertSet('selectedShiftTemplateKey', 'night-shift')
        ->assertSet('showShiftBuilderForm', true)
        ->assertSet('showAllShiftTemplates', false)
        ->call('useShiftTemplate', 'night-shift')
        ->assertSet('shiftCode', '')
        ->assertSet('showShiftBuilderForm', false)
        ->assertSet('showAllShiftTemplates', true);
});

it('removes and toggles shift break rows in the builder', function (): void {
    [$user] = coverageAdmin();

    $this->actingAs($user);

    Livewire::test(ShiftTemplates::class)
        ->call('startNewShift')
        ->call('addShiftBreak')
        ->set('shiftBreaks.0.label', 'Tea')
        ->call('addShiftBreak')
        ->set('shiftBreaks.1.label', 'Lunch')
        ->call('toggleShiftBreakPaid', 1)
        ->assertSet('shiftBreaks.1.paid', true)
        ->call('toggleShiftBreakPaid', 1)
        ->assertSet('shiftBreaks.1.paid', false)
        ->call('removeShiftBreak', 0)
        ->assertCount('shiftBreaks', 1)
        ->assertSet('shiftBreaks.0.label', 'Lunch')
        ->call('toggleShiftBreakPaid', 1)
        ->assertCount('shiftBreaks', 2)
        ->assertSet('shiftBreaks.1.label', 'Break')
        ->assertSet('shiftBreaks.1.paid', true);
});

// ─── PolicyGroups ─────────────────────────────────────────────────────────────

it('toggles a policy group status and refuses foreign or unauthorized toggles', function (): void {
    [$user, $company] = coverageAdmin();
    $policy = coveragePolicy($company);
    $otherCompany = Company::factory()->minimal()->create();
    $foreign = coveragePolicy($otherCompany);

    $this->actingAs($user);

    Livewire::test(PolicyGroups::class)
        ->call('togglePolicyStatus', $policy->id)
        ->assertHasNoErrors()
        ->assertDispatched('notify', coverageNotified('Policy status updated.'));

    expect($policy->refresh()->status)->toBe(AttendancePolicyGroup::STATUS_INACTIVE);

    expect(fn () => Livewire::test(PolicyGroups::class)->call('togglePolicyStatus', $foreign->id))
        ->toThrow(ModelNotFoundException::class);

    $this->actingAs(coverageViewOnlyUser($company));

    expect(fn () => Livewire::test(PolicyGroups::class)->call('togglePolicyStatus', $policy->id))
        ->toThrow(AuthorizationDeniedException::class);

    expect($policy->refresh()->status)->toBe(AttendancePolicyGroup::STATUS_INACTIVE)
        ->and($foreign->refresh()->status)->toBe(AttendancePolicyGroup::STATUS_ACTIVE);
});

it('duplicates a policy group into an inactive unsaved copy', function (): void {
    [$user, $company] = coverageAdmin();
    $policy = coveragePolicy($company);

    $this->actingAs($user);

    Livewire::test(PolicyGroups::class)
        ->call('duplicatePolicyGroup', $policy->id)
        ->assertHasNoErrors()
        ->assertSet('mode', 'form')
        ->assertSet('editingPolicyGroupId', null)
        ->assertSet('policyCode', 'STD_COPY')
        ->assertSet('policyName', 'Standard Copy')
        ->assertSet('policyStatus', AttendancePolicyGroup::STATUS_INACTIVE);

    expect(AttendancePolicyGroup::query()->where('company_id', $company->id)->count())->toBe(1);

    $this->actingAs(coverageViewOnlyUser($company));

    expect(fn () => Livewire::test(PolicyGroups::class)->call('duplicatePolicyGroup', $policy->id))
        ->toThrow(AuthorizationDeniedException::class);
});

it('deletes a policy group and refuses foreign or unauthorized deletes', function (): void {
    [$user, $company] = coverageAdmin();
    $policy = coveragePolicy($company);
    $otherCompany = Company::factory()->minimal()->create();
    $foreign = coveragePolicy($otherCompany);

    $this->actingAs($user);

    expect(fn () => Livewire::test(PolicyGroups::class)->call('deletePolicyGroup', $foreign->id))
        ->toThrow(ModelNotFoundException::class);

    $this->actingAs(coverageViewOnlyUser($company));

    expect(fn () => Livewire::test(PolicyGroups::class)->call('deletePolicyGroup', $policy->id))
        ->toThrow(AuthorizationDeniedException::class);

    expect(AttendancePolicyGroup::query()->whereKey($policy->id)->exists())->toBeTrue()
        ->and(AttendancePolicyGroup::query()->whereKey($foreign->id)->exists())->toBeTrue();

    $this->actingAs($user);

    Livewire::test(PolicyGroups::class)
        ->call('deletePolicyGroup', $policy->id)
        ->assertHasNoErrors()
        ->assertDispatched('notify', coverageNotified('Policy group deleted.'));

    expect(AttendancePolicyGroup::query()->whereKey($policy->id)->exists())->toBeFalse();
});

// ─── AllowanceRules ───────────────────────────────────────────────────────────

it('toggles an allowance rule status and refuses foreign or unauthorized toggles', function (): void {
    [$user, $company] = coverageAdmin();
    $rule = coverageAllowanceRule($company, coveragePolicy($company));
    $otherCompany = Company::factory()->minimal()->create();
    $foreign = coverageAllowanceRule($otherCompany, coveragePolicy($otherCompany));

    $this->actingAs($user);

    Livewire::test(AllowanceRules::class)
        ->call('toggleAllowanceStatus', $rule->id)
        ->assertHasNoErrors()
        ->assertDispatched('notify', coverageNotified('Allowance rule status updated.'));

    expect($rule->refresh()->status)->toBe('inactive');

    expect(fn () => Livewire::test(AllowanceRules::class)->call('toggleAllowanceStatus', $foreign->id))
        ->toThrow(ModelNotFoundException::class);

    $this->actingAs(coverageViewOnlyUser($company));

    expect(fn () => Livewire::test(AllowanceRules::class)->call('toggleAllowanceStatus', $rule->id))
        ->toThrow(AuthorizationDeniedException::class);

    expect($rule->refresh()->status)->toBe('inactive')
        ->and($foreign->refresh()->status)->toBe('active');
});

it('cancels an allowance rule edit without persisting the abandoned changes', function (): void {
    [$user, $company] = coverageAdmin();
    $rule = coverageAllowanceRule($company, coveragePolicy($company));

    $this->actingAs($user);

    Livewire::test(AllowanceRules::class)
        ->call('editAllowanceRule', $rule->id)
        ->assertSet('mode', 'form')
        ->assertSet('editingAllowanceRuleId', $rule->id)
        ->assertSet('allowanceAmount', '10')
        ->set('allowanceAmount', '99.00')
        ->set('allowanceName', 'Abandoned rename')
        ->call('cancelAllowanceEdit')
        ->assertSet('mode', 'list')
        ->assertSet('editingAllowanceRuleId', null)
        ->assertSet('allowanceName', '')
        ->assertSet('allowanceAmount', '0.00')
        ->assertSet('showAllowanceBuilderForm', false)
        ->assertSet('showAllAllowanceTemplates', true);

    expect($rule->refresh()->name)->toBe('Meal allowance')
        ->and($rule->condition_rows[0]['amount'])->toBe(10);
});
