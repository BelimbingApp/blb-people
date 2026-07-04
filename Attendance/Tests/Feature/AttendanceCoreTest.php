<?php

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use App\Modules\People\Attendance\Exceptions\AttendanceAdjustmentException;
use App\Modules\People\Attendance\Exceptions\AttendanceClockEventIngestionException;
use App\Modules\People\Attendance\Exceptions\AttendanceLifecycleException;
use App\Modules\People\Attendance\Livewire\Approvals;
use App\Modules\People\Attendance\Livewire\MyAttendance;
use App\Modules\People\Attendance\Models\AttendanceAdjustmentRequest;
use App\Modules\People\Attendance\Models\AttendanceClockEvent;
use App\Modules\People\Attendance\Models\AttendanceDay;
use App\Modules\People\Attendance\Models\AttendanceOvertimeRequest;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use App\Modules\People\Attendance\Models\AttendanceRosterPattern;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use App\Modules\People\Attendance\Services\AttendanceAdjustmentService;
use App\Modules\People\Attendance\Services\AttendanceCalendarResolver;
use App\Modules\People\Attendance\Services\AttendanceDayProjectionService;
use App\Modules\People\Attendance\Services\AttendanceDayResolverService;
use App\Modules\People\Attendance\Services\AttendanceLifecycleService;
use App\Modules\People\Attendance\Services\AttendanceOvertimeService;
use App\Modules\People\Attendance\Services\AttendancePolicyGroupResolver;
use App\Modules\People\Attendance\Services\ClockEventIngestionService;
use App\Modules\People\Attendance\Support\DayTypeVocabulary;
use App\Modules\People\Payroll\Listeners\RecordAttendanceOvertimeContribution;
use App\Modules\People\Payroll\Models\PayrollCalendar;
use App\Modules\People\Payroll\Models\PayrollInput;
use App\Modules\People\Payroll\Models\PayrollPeriod;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Settings\Models\EmployeeWorkProfile;
use App\Modules\People\Settings\Models\PeopleCalendarException;
use App\Modules\People\Settings\Models\PeopleReferenceEntry;
use Livewire\Livewire;

const ATTENDANCE_EFFECTIVE_FROM = '2026-01-01';
const ATTENDANCE_TEST_DATE = '2026-05-13';
const ATTENDANCE_HOLIDAY_DATE = '2026-05-14';
const ATTENDANCE_FRIDAY_DATE = '2026-05-15';
const ATTENDANCE_DAY_SHIFT_NAME = 'Day Shift';
const ATTENDANCE_STANDARD_POLICY_NAME = 'Standard Attendance';
const ATTENDANCE_PROPOSED_CLOCK_IN = '2026-05-13 08:05:00';

/** @return array{0: Company, 1: Employee} */
function attendanceEmployee(): array
{
    $company = Company::factory()->minimal()->create();

    return [
        $company,
        Employee::factory()->active()->create(['company_id' => $company->id]),
    ];
}

/** @param array<string, mixed> $attributes */
function attendanceShiftTemplate(Company $company, array $attributes = []): AttendanceShiftTemplate
{
    return AttendanceShiftTemplate::query()->create(array_replace([
        'company_id' => $company->id,
        'code' => 'DAY',
        'name' => 'Day',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => ATTENDANCE_EFFECTIVE_FROM,
    ], $attributes));
}

/** @param array<string, mixed> $attributes */
function attendancePolicyGroup(Company $company, array $attributes = []): AttendancePolicyGroup
{
    return AttendancePolicyGroup::query()->create(array_replace([
        'company_id' => $company->id,
        'code' => 'STD',
        'name' => 'Standard',
        'effective_from' => ATTENDANCE_EFFECTIVE_FROM,
    ], $attributes));
}

/** @param array<string, mixed> $attributes */
function attendanceDay(
    Company $company,
    Employee $employee,
    ?AttendanceShiftTemplate $shift = null,
    ?AttendancePolicyGroup $policyGroup = null,
    array $attributes = [],
): AttendanceDay {
    return AttendanceDay::query()->create(array_replace([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_shift_template_id' => $shift?->id,
        'attendance_policy_group_id' => $policyGroup?->id,
        'attendance_date' => ATTENDANCE_TEST_DATE,
        'expected_minutes' => $shift?->expected_work_minutes ?? 480,
        'payroll_period_date' => ATTENDANCE_TEST_DATE,
    ], $attributes));
}

function attendanceClockEvent(
    Company $company,
    Employee $employee,
    AttendanceDay $day,
    string $eventType,
    string $occurredAt,
): AttendanceClockEvent {
    return AttendanceClockEvent::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_day_id' => $day->id,
        'event_type' => $eventType,
        'occurred_at' => $occurredAt,
        'source' => AttendanceClockEvent::SOURCE_WEB,
    ]);
}

function projectAttendanceDay(AttendanceDay $day): AttendanceDay
{
    app(AttendanceDayProjectionService::class)->project($day)->save();

    return $day->refresh();
}

/**
 * @param  array<string, mixed>  $shiftAttributes
 * @param  array<string, mixed>  $policyAttributes
 */
function projectedAttendanceDay(array $shiftAttributes, array $policyAttributes, string $clockIn, string $clockOut): AttendanceDay
{
    [$company, $employee] = attendanceEmployee();
    $shift = attendanceShiftTemplate($company, $shiftAttributes);
    $policyGroup = attendancePolicyGroup($company, $policyAttributes);
    $day = attendanceDay($company, $employee, $shift, $policyGroup);

    attendanceClockEvent($company, $employee, $day, AttendanceClockEvent::TYPE_IN, $clockIn);
    attendanceClockEvent($company, $employee, $day, AttendanceClockEvent::TYPE_OUT, $clockOut);

    return projectAttendanceDay($day);
}

function assignAttendanceRoster(
    Company $company,
    Employee $employee,
    AttendanceShiftTemplate $shift,
    AttendancePolicyGroup $policyGroup,
): AttendanceRosterAssignment {
    return AttendanceRosterAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_shift_template_id' => $shift->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'effective_from' => '2026-05-01',
        'publish_state' => 'published',
    ]);
}

/** @return array{0: Company, 1: Employee, 2: PeopleReferenceEntry} */
function employeeWithMalaysiaWorkCalendar(): array
{
    [$company, $employee] = attendanceEmployee();
    $workCalendar = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id,
        'type' => PeopleReferenceEntry::TYPE_WORK_CALENDAR,
        'code' => 'MY-STD',
        'name' => 'Malaysia Standard',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
        'metadata' => ['rest_days' => ['sunday'], 'off_days' => ['saturday']],
    ]);

    EmployeeWorkProfile::query()->create([
        'employee_id' => $employee->id,
        'work_calendar_id' => $workCalendar->id,
        'hired_on' => ATTENDANCE_EFFECTIVE_FROM,
    ]);

    return [$company, $employee, $workCalendar];
}

function createWesakCalendarException(PeopleReferenceEntry $workCalendar): PeopleCalendarException
{
    return PeopleCalendarException::query()->create([
        'work_calendar_id' => $workCalendar->id,
        'occurs_on' => ATTENDANCE_HOLIDAY_DATE,
        'name' => 'Wesak Day',
        'kind' => 'public_holiday',
    ]);
}

function createMayPayrollRun(Company $company): PayrollRun
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

it('projects attendance day metrics from clock events', function (): void {
    [$company, $employee] = attendanceEmployee();
    $shift = attendanceShiftTemplate($company, ['name' => ATTENDANCE_DAY_SHIFT_NAME]);
    $policyGroup = attendancePolicyGroup($company, ['name' => ATTENDANCE_STANDARD_POLICY_NAME]);
    $day = attendanceDay($company, $employee, $shift, $policyGroup, [
        'shift_starts_at' => '2026-05-13 08:00:00',
        'shift_ends_at' => '2026-05-13 17:00:00',
    ]);

    attendanceClockEvent($company, $employee, $day, AttendanceClockEvent::TYPE_IN, '2026-05-13 08:12:00');
    attendanceClockEvent($company, $employee, $day, AttendanceClockEvent::TYPE_OUT, '2026-05-13 17:30:00');
    $day = projectAttendanceDay($day);

    expect($day->status)->toBe(AttendanceDay::STATUS_EXCEPTION_PENDING)
        ->and($day->worked_minutes)->toBe(558)
        ->and($day->payable_minutes)->toBe(480)
        ->and($day->late_minutes)->toBe(12)
        ->and($day->early_out_minutes)->toBe(0)
        ->and($day->absent_minutes)->toBe(0)
        ->and($day->overtime_candidate_minutes)->toBe(78)
        ->and($day->exception_tags)->toBe(['late_in']);
});

it('deducts unpaid breaks from worked time but keeps paid breaks counted', function (): void {
    $day = projectedAttendanceDay([
        'code' => 'PROD_12H',
        'name' => 'Production 12h',
        'starts_at' => '07:00:00',
        'ends_at' => '19:00:00',
        'expected_work_minutes' => 660,
        'break_windows' => [
            ['label' => 'Lunch', 'starts_at' => '12:00', 'ends_at' => '13:00', 'paid' => false],
            ['label' => 'Tea', 'starts_at' => '15:30', 'ends_at' => '15:45', 'paid' => true],
        ],
    ], [
        'code' => 'PROD',
        'name' => 'Production',
    ], '2026-05-13 07:00:00', '2026-05-13 19:00:00');

    // 07:00–19:00 = 720 min raw. Unpaid lunch (60 min) deducted; paid tea kept. Worked = 660.
    expect($day->worked_minutes)->toBe(660)
        ->and($day->break_minutes)->toBe(75)
        ->and($day->payable_minutes)->toBe(660)
        ->and($day->overtime_candidate_minutes)->toBe(0);
});

it('applies lateness grace and rounding from the policy group', function (): void {
    $day = projectedAttendanceDay([], [
        'code' => 'GRACE',
        'name' => 'Grace policy',
        'lateness_rules' => [
            'grace' => ['in' => 10, 'out' => 5],
            'daily_rounding' => ['method' => 'ceiling', 'minutes' => 5],
        ],
    ], '2026-05-13 08:12:00', '2026-05-13 16:57:00');

    // Late: 12 min - 10 min grace = 2 min → ceiling 5 = 5.
    // Early out: 3 min - 5 min grace = 0.
    expect($day->late_minutes)->toBe(5)
        ->and($day->early_out_minutes)->toBe(0);
});

it('rounds worked minutes and suppresses overtime candidates below the OT minimum', function (): void {
    $day = projectedAttendanceDay([], [
        'code' => 'TIGHT',
        'name' => 'Tight policy',
        'work_hour_rules' => ['daily_rounding' => ['method' => 'nearest', 'minutes' => 15]],
        'overtime_rules' => ['late_ot' => ['enabled' => true, 'minimum_minutes' => 60]],
    ], '2026-05-13 08:00:00', '2026-05-13 17:25:00');

    // Raw worked = 565 min; nearest 15 = 570. Excess over 480 = 90 → above 60-min threshold → kept as OT candidate.
    expect($day->worked_minutes)->toBe(570)
        ->and($day->overtime_candidate_minutes)->toBe(90);
});

it('zeroes the overtime candidate when the excess falls below the OT minimum threshold', function (): void {
    $day = projectedAttendanceDay([
        'expected_work_minutes' => 540,
    ], [
        'code' => 'OT60',
        'name' => '60-min OT threshold',
        'overtime_rules' => ['late_ot' => ['enabled' => true, 'minimum_minutes' => 60]],
    ], '2026-05-13 08:00:00', '2026-05-13 17:30:00');

    // 570 worked, 30-min excess over 540 expected; below the 60-min OT threshold → suppressed.
    expect($day->worked_minutes)->toBe(570)
        ->and($day->overtime_candidate_minutes)->toBe(0);
});

it('prefers employee roster policy groups over cohort defaults', function (): void {
    [$company, $employee] = attendanceEmployee();
    $defaultGroup = attendancePolicyGroup($company, [
        'code' => 'DEFAULT',
        'name' => 'Default',
    ]);
    $employeeGroup = attendancePolicyGroup($company, [
        'code' => 'EMPLOYEE',
        'name' => 'Employee Specific',
    ]);

    AttendanceRosterAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => null,
        'attendance_policy_group_id' => $defaultGroup->id,
        'effective_from' => ATTENDANCE_EFFECTIVE_FROM,
        'publish_state' => 'published',
    ]);
    AttendanceRosterAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_policy_group_id' => $employeeGroup->id,
        'effective_from' => ATTENDANCE_EFFECTIVE_FROM,
        'publish_state' => 'published',
    ]);

    $resolved = app(AttendancePolicyGroupResolver::class)->resolveForEmployee($employee, ATTENDANCE_TEST_DATE);

    expect($resolved?->is($employeeGroup))->toBeTrue();
});

it('ingests web clock events through an append-only service and projects partial punches as exceptions', function (): void {
    [$company, $employee] = attendanceEmployee();
    $actor = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
    ]);

    $event = app(ClockEventIngestionService::class)->recordWebClock(
        employee: $employee,
        eventType: AttendanceClockEvent::TYPE_IN,
        actorUserId: $actor->id,
        ipAddress: '127.0.0.1',
        occurredAt: ATTENDANCE_PROPOSED_CLOCK_IN,
        timezone: 'Asia/Singapore',
    );

    $day = AttendanceDay::query()->whereKey($event->attendance_day_id)->firstOrFail();

    expect($event->source)->toBe(AttendanceClockEvent::SOURCE_WEB)
        ->and($event->timezone)->toBe('Asia/Singapore')
        ->and($event->actor_user_id)->toBe($actor->id)
        ->and($event->ip_address)->toBe('127.0.0.1')
        ->and($day->status)->toBe(AttendanceDay::STATUS_EXCEPTION_PENDING)
        ->and($day->exception_tags)->toBe(['missing_clock_out']);
});

it('records manual corrections without mutating the original clock event', function (): void {
    [$company, $employee] = attendanceEmployee();
    $actor = User::factory()->create(['company_id' => $company->id]);
    $service = app(ClockEventIngestionService::class);

    $original = $service->importClockEvent(
        employee: $employee,
        eventType: AttendanceClockEvent::TYPE_IN,
        occurredAt: '2026-05-13 08:30:00',
        sourceSystem: 'legacy-device',
        sourceCode: 'PUNCH-1001',
        attributes: ['timezone' => 'Asia/Singapore'],
    );

    $correction = $service->correctClockEvent(
        correctedEvent: $original,
        eventType: AttendanceClockEvent::TYPE_IN,
        occurredAt: '2026-05-13 08:00:00',
        actorUserId: $actor->id,
    );

    $original->refresh();

    expect($original->occurred_at->format('H:i:s'))->toBe('08:30:00')
        ->and($correction->source)->toBe(AttendanceClockEvent::SOURCE_MANUAL)
        ->and($correction->corrects_clock_event_id)->toBe($original->id)
        ->and(AttendanceClockEvent::query()->count())->toBe(2);
});

it('blocks new clock events on locked attendance days', function (): void {
    [$company, $employee] = attendanceEmployee();
    $actor = User::factory()->create(['company_id' => $company->id]);

    AttendanceDay::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_date' => ATTENDANCE_TEST_DATE,
        'status' => AttendanceDay::STATUS_LOCKED,
        'locked_at' => now(),
    ]);

    app(ClockEventIngestionService::class)->recordManualClock(
        employee: $employee,
        eventType: AttendanceClockEvent::TYPE_IN,
        occurredAt: '2026-05-13 08:00:00',
        actorUserId: $actor->id,
        attributes: ['timezone' => 'Asia/Singapore'],
    );
})->throws(AttendanceClockEventIngestionException::class);

it('resolves attendance days from rotating roster assignments', function (): void {
    [$company, $employee] = attendanceEmployee();
    $dayShift = attendanceShiftTemplate($company, ['name' => ATTENDANCE_DAY_SHIFT_NAME]);
    $nightShift = attendanceShiftTemplate($company, [
        'code' => 'NIGHT',
        'name' => 'Night Shift',
        'starts_at' => '20:00:00',
        'ends_at' => '08:00:00',
        'crosses_midnight' => true,
        'expected_work_minutes' => 720,
    ]);
    $policyGroup = attendancePolicyGroup($company, [
        'name' => ATTENDANCE_STANDARD_POLICY_NAME,
    ]);
    $pattern = AttendanceRosterPattern::query()->create([
        'company_id' => $company->id,
        'code' => 'ROTATE',
        'name' => 'Rotation',
        'pattern_type' => AttendanceRosterPattern::TYPE_ROTATING,
        'pattern_definition' => [
            'cycle_days' => 2,
            'days' => [
                ['offset' => 0, 'shift_code' => $dayShift->code],
                ['offset' => 1, 'shift_code' => $nightShift->code],
            ],
        ],
        'status' => AttendanceRosterPattern::STATUS_PUBLISHED,
    ]);
    AttendanceRosterAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_roster_pattern_id' => $pattern->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'effective_from' => ATTENDANCE_TEST_DATE,
        'publish_state' => 'published',
    ]);

    $day = app(AttendanceDayResolverService::class)->resolve($employee, ATTENDANCE_HOLIDAY_DATE);

    expect($day->shiftTemplate?->is($nightShift))->toBeTrue()
        ->and($day->status)->toBe(AttendanceDay::STATUS_SCHEDULED)
        ->and($day->expected_minutes)->toBe(720)
        ->and($day->shift_ends_at?->toDateString())->toBe(ATTENDANCE_FRIDAY_DATE);
});

it('rolls the payroll period date forward for cross-midnight shifts attributed to shift_end_date', function (): void {
    $company = Company::factory()->minimal()->create();
    $endEmployee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $startEmployee = Employee::factory()->active()->create(['company_id' => $company->id]);

    $endDateShift = attendanceShiftTemplate($company, [
        'code' => 'NIGHT_END',
        'name' => 'Night attributed to end date',
        'starts_at' => '20:00:00',
        'ends_at' => '05:00:00',
        'crosses_midnight' => true,
        'expected_work_minutes' => 480,
        'cross_midnight_attribution' => 'shift_end_date',
    ]);
    $startDateShift = attendanceShiftTemplate($company, [
        'code' => 'NIGHT_START',
        'name' => 'Night attributed to start date',
        'starts_at' => '20:00:00',
        'ends_at' => '05:00:00',
        'crosses_midnight' => true,
        'expected_work_minutes' => 480,
        'cross_midnight_attribution' => 'shift_start_date',
    ]);
    $policyGroup = attendancePolicyGroup($company);

    AttendanceRosterAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $endEmployee->id,
        'attendance_shift_template_id' => $endDateShift->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'effective_from' => ATTENDANCE_TEST_DATE,
        'publish_state' => 'published',
    ]);
    AttendanceRosterAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $startEmployee->id,
        'attendance_shift_template_id' => $startDateShift->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'effective_from' => ATTENDANCE_TEST_DATE,
        'publish_state' => 'published',
    ]);

    $resolver = app(AttendanceDayResolverService::class);
    $endAttributed = $resolver->resolve($endEmployee, ATTENDANCE_TEST_DATE);
    $startAttributed = $resolver->resolve($startEmployee, ATTENDANCE_TEST_DATE);

    expect($endAttributed->payroll_period_date?->toDateString())->toBe(ATTENDANCE_HOLIDAY_DATE)
        ->and($startAttributed->payroll_period_date?->toDateString())->toBe(ATTENDANCE_TEST_DATE);
});

it('projects worked minutes across midnight on a night shift', function (): void {
    $day = projectedAttendanceDay([
        'code' => 'NIGHT',
        'name' => 'Night',
        'starts_at' => '20:00:00',
        'ends_at' => '05:00:00',
        'crosses_midnight' => true,
        'expected_work_minutes' => 480,
        'break_windows' => [['label' => 'Midnight', 'starts_at' => '00:00', 'ends_at' => '01:00', 'paid' => false]],
    ], [
        'code' => 'NIGHTPOL',
        'name' => 'Night policy',
    ], '2026-05-13 20:00:00', '2026-05-14 05:00:00');

    // Span 20:00 Mon → 05:00 Tue = 540 min. Midnight break (00:00–01:00 next day, unpaid) = 60.
    // Worked = 480, expected = 480, no OT.
    expect($day->worked_minutes)->toBe(480)
        ->and($day->break_minutes)->toBe(60)
        ->and($day->payable_minutes)->toBe(480)
        ->and($day->overtime_candidate_minutes)->toBe(0);
});

it('approves a missing-punch adjustment by creating a manual clock event from the request', function (): void {
    [$company, $employee] = attendanceEmployee();
    $user = User::factory()->create(['company_id' => $company->id]);
    $shift = attendanceShiftTemplate($company);
    $policyGroup = attendancePolicyGroup($company);
    assignAttendanceRoster($company, $employee, $shift, $policyGroup);

    $request = AttendanceAdjustmentRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'request_mode' => AttendanceAdjustmentRequest::MODE_MISSING_PUNCH,
        'target_event_type' => AttendanceClockEvent::TYPE_IN,
        'proposed_occurred_at' => ATTENDANCE_PROPOSED_CLOCK_IN,
        'reason' => 'Forgot to clock in; was at desk at 08:05.',
        'status' => AttendanceAdjustmentRequest::STATUS_DRAFT,
    ]);

    $service = app(AttendanceAdjustmentService::class);
    $service->submit($request, $employee->id);
    $service->approve($request->refresh(), $user->id, 'Verified by supervisor.');
    $request->refresh();

    expect($request->status)->toBe(AttendanceAdjustmentRequest::STATUS_APPROVED)
        ->and($request->applied_clock_event_id)->not->toBeNull()
        ->and($request->attendance_day_id)->not->toBeNull();

    $clockEvent = AttendanceClockEvent::query()->find($request->applied_clock_event_id);
    expect($clockEvent->event_type)->toBe(AttendanceClockEvent::TYPE_IN)
        ->and($clockEvent->source)->toBe(AttendanceClockEvent::SOURCE_MANUAL)
        ->and($clockEvent->actor_user_id)->toBe($user->id)
        ->and($clockEvent->corrects_clock_event_id)->toBeNull()
        ->and($clockEvent->metadata['adjustment_request_id'] ?? null)->toBe($request->id);
});

it('approves a correct-existing adjustment by creating a correction event linked to the original', function (): void {
    [$company, $employee] = attendanceEmployee();
    $user = User::factory()->create(['company_id' => $company->id]);
    $shift = attendanceShiftTemplate($company);
    $policyGroup = attendancePolicyGroup($company);
    assignAttendanceRoster($company, $employee, $shift, $policyGroup);

    $original = app(ClockEventIngestionService::class)->recordManualClock(
        $employee,
        AttendanceClockEvent::TYPE_IN,
        '2026-05-13 08:30:00',
        $user->id,
    );

    $request = AttendanceAdjustmentRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'corrects_clock_event_id' => $original->id,
        'request_mode' => AttendanceAdjustmentRequest::MODE_CORRECT_EXISTING,
        'target_event_type' => AttendanceClockEvent::TYPE_IN,
        'proposed_occurred_at' => '2026-05-13 08:00:00',
        'reason' => 'Card reader was 30 minutes slow; actual clock-in was 08:00.',
        'status' => AttendanceAdjustmentRequest::STATUS_SUBMITTED,
        'submitted_by_user_id' => $employee->id,
        'submitted_at' => now(),
    ]);

    app(AttendanceAdjustmentService::class)->approve($request, $user->id);
    $request->refresh();

    $correctingEvent = AttendanceClockEvent::query()->find($request->applied_clock_event_id);

    expect($request->status)->toBe(AttendanceAdjustmentRequest::STATUS_APPROVED)
        ->and($correctingEvent->corrects_clock_event_id)->toBe($original->id)
        ->and($correctingEvent->source)->toBe(AttendanceClockEvent::SOURCE_MANUAL)
        ->and($correctingEvent->occurred_at->format('H:i'))->toBe('08:00');
});

it('rejects an adjustment request without creating a clock event', function (): void {
    [$company, $employee] = attendanceEmployee();
    $user = User::factory()->create(['company_id' => $company->id]);

    $request = AttendanceAdjustmentRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'request_mode' => AttendanceAdjustmentRequest::MODE_MISSING_PUNCH,
        'target_event_type' => AttendanceClockEvent::TYPE_IN,
        'proposed_occurred_at' => '2026-05-13 08:00:00',
        'status' => AttendanceAdjustmentRequest::STATUS_SUBMITTED,
        'submitted_by_user_id' => $employee->id,
        'submitted_at' => now(),
    ]);

    app(AttendanceAdjustmentService::class)->reject($request, $user->id, 'No corroborating evidence.');
    $request->refresh();

    expect($request->status)->toBe(AttendanceAdjustmentRequest::STATUS_REJECTED)
        ->and($request->applied_clock_event_id)->toBeNull()
        ->and($request->decision_reason)->toBe('No corroborating evidence.')
        ->and(AttendanceClockEvent::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

it('blocks invalid adjustment-request transitions', function (): void {
    [$company, $employee] = attendanceEmployee();
    $user = User::factory()->create(['company_id' => $company->id]);

    $request = AttendanceAdjustmentRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'request_mode' => AttendanceAdjustmentRequest::MODE_MISSING_PUNCH,
        'target_event_type' => AttendanceClockEvent::TYPE_IN,
        'proposed_occurred_at' => '2026-05-13 08:00:00',
        'status' => AttendanceAdjustmentRequest::STATUS_REJECTED,
        'rejected_at' => now(),
    ]);

    app(AttendanceAdjustmentService::class)->approve($request, $user->id);
})->throws(AttendanceAdjustmentException::class);

it('flags a public holiday from the employee work calendar as a holiday day type', function (): void {
    [, $employee, $workCalendar] = employeeWithMalaysiaWorkCalendar();
    createWesakCalendarException($workCalendar);

    $dayType = app(AttendanceCalendarResolver::class)->dayType($employee, ATTENDANCE_HOLIDAY_DATE);

    expect($dayType)->toBe(AttendanceDay::DAY_TYPE_HOLIDAY);
});

it('derives weekly rest and off day types from the work calendar metadata', function (): void {
    [, $employee] = employeeWithMalaysiaWorkCalendar();

    $resolver = app(AttendanceCalendarResolver::class);

    // 2026-05-17 is a Sunday → rest.
    // 2026-05-16 is a Saturday → off.
    // 2026-05-15 is a Friday → normal.
    expect($resolver->dayType($employee, '2026-05-17'))->toBe(AttendanceDay::DAY_TYPE_REST)
        ->and($resolver->dayType($employee, '2026-05-16'))->toBe(AttendanceDay::DAY_TYPE_OFF)
        ->and($resolver->dayType($employee, ATTENDANCE_FRIDAY_DATE))->toBe(AttendanceDay::DAY_TYPE_NORMAL);
});

it('treats employees without a work calendar as normal day type', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);

    $dayType = app(AttendanceCalendarResolver::class)->dayType($employee, '2026-05-17');

    expect($dayType)->toBe(AttendanceDay::DAY_TYPE_NORMAL);
});

it('returns consistent label + surface + ink classes for every day type', function (): void {
    expect(DayTypeVocabulary::label(AttendanceDay::DAY_TYPE_NORMAL))->toBe('Normal')
        ->and(DayTypeVocabulary::label(AttendanceDay::DAY_TYPE_REST))->toBe('Rest')
        ->and(DayTypeVocabulary::label(AttendanceDay::DAY_TYPE_OFF))->toBe('Off')
        ->and(DayTypeVocabulary::label(AttendanceDay::DAY_TYPE_HOLIDAY))->toBe('Holiday');

    expect(DayTypeVocabulary::surfaceClass(AttendanceDay::DAY_TYPE_NORMAL))->toBe('')
        ->and(DayTypeVocabulary::surfaceClass(AttendanceDay::DAY_TYPE_REST))->toBe('bg-day-rest')
        ->and(DayTypeVocabulary::surfaceClass(AttendanceDay::DAY_TYPE_OFF))->toBe('bg-day-off')
        ->and(DayTypeVocabulary::surfaceClass(AttendanceDay::DAY_TYPE_HOLIDAY))->toBe('bg-day-holiday');

    expect(DayTypeVocabulary::inkClass(AttendanceDay::DAY_TYPE_NORMAL))->toBe('text-muted')
        ->and(DayTypeVocabulary::inkClass(AttendanceDay::DAY_TYPE_REST))->toBe('text-day-rest-ink')
        ->and(DayTypeVocabulary::inkClass(AttendanceDay::DAY_TYPE_OFF))->toBe('text-day-off-ink')
        ->and(DayTypeVocabulary::inkClass(AttendanceDay::DAY_TYPE_HOLIDAY))->toBe('text-day-holiday-ink');

    expect(DayTypeVocabulary::isNonWorking(AttendanceDay::DAY_TYPE_NORMAL))->toBeFalse()
        ->and(DayTypeVocabulary::isNonWorking(AttendanceDay::DAY_TYPE_REST))->toBeTrue()
        ->and(DayTypeVocabulary::isNonWorking(AttendanceDay::DAY_TYPE_OFF))->toBeTrue()
        ->and(DayTypeVocabulary::isNonWorking(AttendanceDay::DAY_TYPE_HOLIDAY))->toBeTrue();
});

it('preloads holiday and calendar lookups so the roster grid resolves day types without per-cell queries', function (): void {
    [, $employee, $workCalendar] = employeeWithMalaysiaWorkCalendar();
    createWesakCalendarException($workCalendar);

    $resolver = new AttendanceCalendarResolver;
    $resolver->preload([$employee], '2026-05-11', '2026-05-17');

    DB::enableQueryLog();
    DB::flushQueryLog();

    expect($resolver->dayType($employee, ATTENDANCE_HOLIDAY_DATE))->toBe(AttendanceDay::DAY_TYPE_HOLIDAY)
        ->and($resolver->dayType($employee, '2026-05-17'))->toBe(AttendanceDay::DAY_TYPE_REST)
        ->and($resolver->dayType($employee, '2026-05-16'))->toBe(AttendanceDay::DAY_TYPE_OFF)
        ->and($resolver->dayType($employee, ATTENDANCE_FRIDAY_DATE))->toBe(AttendanceDay::DAY_TYPE_NORMAL);

    expect(DB::getQueryLog())->toBeEmpty();
});

it('routes a fixed weekly roster pattern through the day_types map when a holiday falls on a working weekday', function (): void {
    [$company, $employee, $workCalendar] = employeeWithMalaysiaWorkCalendar();
    createWesakCalendarException($workCalendar);

    attendanceShiftTemplate($company, [
        'code' => 'PROD_DAY',
        'name' => 'Production day',
    ]);
    attendanceShiftTemplate($company, [
        'code' => 'PROD_HOLIDAY_HALF',
        'name' => 'Production half-day on holidays',
        'ends_at' => '12:00:00',
        'expected_work_minutes' => 240,
    ]);
    $policyGroup = attendancePolicyGroup($company);
    $pattern = AttendanceRosterPattern::query()->create([
        'company_id' => $company->id,
        'code' => 'WEEKLY',
        'name' => 'Weekly',
        'pattern_type' => AttendanceRosterPattern::TYPE_FIXED_WEEKLY,
        'pattern_definition' => [
            'weekdays' => [
                'thursday' => ['shift_code' => 'PROD_DAY'],
            ],
            'day_types' => [
                AttendanceDay::DAY_TYPE_HOLIDAY => ['shift_code' => 'PROD_HOLIDAY_HALF'],
                AttendanceDay::DAY_TYPE_REST => ['shift_code' => null],
            ],
        ],
        'status' => AttendanceRosterPattern::STATUS_PUBLISHED,
    ]);
    AttendanceRosterAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_roster_pattern_id' => $pattern->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'effective_from' => '2026-05-01',
        'publish_state' => 'published',
    ]);

    $resolver = app(AttendanceDayResolverService::class);
    $holidayDay = $resolver->resolve($employee, ATTENDANCE_HOLIDAY_DATE);   // Thursday + holiday → PROD_HOLIDAY_HALF
    $normalDay = $resolver->resolve($employee, '2026-05-21');    // Thursday, no holiday → PROD_DAY
    $restDay = $resolver->resolve($employee, '2026-05-17');      // Sunday → rest, no shift

    expect($holidayDay->day_type)->toBe(AttendanceDay::DAY_TYPE_HOLIDAY)
        ->and($holidayDay->shiftTemplate?->code)->toBe('PROD_HOLIDAY_HALF')
        ->and($normalDay->day_type)->toBe(AttendanceDay::DAY_TYPE_NORMAL)
        ->and($normalDay->shiftTemplate?->code)->toBe('PROD_DAY')
        ->and($restDay->day_type)->toBe(AttendanceDay::DAY_TYPE_REST)
        ->and($restDay->shiftTemplate)->toBeNull();
});

it('finalizes ready attendance days and blocks locked lifecycle changes', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $day = AttendanceDay::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_date' => ATTENDANCE_TEST_DATE,
        'status' => AttendanceDay::STATUS_READY_FOR_REVIEW,
    ]);

    app(AttendanceLifecycleService::class)->finalize($day);
    app(AttendanceLifecycleService::class)->lock($day);

    expect($day->refresh()->locked_at)->not->toBeNull();

    app(AttendanceLifecycleService::class)->finalize($day);
})->throws(AttendanceLifecycleException::class);

it('approves overtime and queues one neutral payroll input', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $policyGroup = attendancePolicyGroup($company, [
        'name' => ATTENDANCE_STANDARD_POLICY_NAME,
    ]);
    $day = AttendanceDay::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'attendance_date' => ATTENDANCE_TEST_DATE,
        'status' => AttendanceDay::STATUS_FINALIZED,
    ]);
    $request = AttendanceOvertimeRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_day_id' => $day->id,
        'status' => AttendanceOvertimeRequest::STATUS_SUBMITTED,
        'starts_at' => '2026-05-13 17:00:00',
        'ends_at' => '2026-05-13 19:00:00',
        'requested_minutes' => 120,
        'reason' => 'Production support',
        'policy_snapshot' => ['overtime_pay_item_code' => 'OT15'],
    ]);
    createMayPayrollRun($company);

    $service = app(AttendanceOvertimeService::class);
    $service->approve($request, 90);
    $dispatchedFirst = $service->queuePayrollHandoff($request);
    $dispatchedAgain = $service->queuePayrollHandoff($request->refresh());

    expect($dispatchedFirst)->toBeTrue()
        ->and($dispatchedAgain)->toBeTrue()
        ->and(PayrollInput::query()->count())->toBe(1)
        ->and(PayrollInput::query()->first()?->pay_item_code)->toBe('OT15')
        ->and(PayrollInput::query()->first()?->quantity)->toBe('1.5000')
        ->and(PayrollInput::query()->first()?->source_type)->toBe(RecordAttendanceOvertimeContribution::SOURCE_TYPE)
        ->and($request->refresh()->status)->toBe(AttendanceOvertimeRequest::STATUS_QUEUED_FOR_PAYROLL);
});

it('lets linked employees submit overtime from the attendance workbench', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $user->forceFill(['employee_id' => $employee->id])->save();

    $this->actingAs($user);

    Livewire::test(MyAttendance::class)
        ->assertSee('Request OT')
        ->call('openOvertimeModal')
        ->set('overtimeDate', ATTENDANCE_TEST_DATE)
        ->set('overtimeStartsAt', '17:00')
        ->set('overtimeEndsAt', '19:00')
        ->set('overtimeRequestedHours', '2.00')
        ->set('overtimeReason', 'Month-end production support')
        ->call('submitOvertimeRequest')
        ->assertHasNoErrors();

    expect(AttendanceOvertimeRequest::query()
        ->where('employee_id', $employee->id)
        ->where('status', AttendanceOvertimeRequest::STATUS_SUBMITTED)
        ->exists())->toBeTrue();
});

it('auto-fills Requested Hours from the start–end duration and stops once the user edits it', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $user->forceFill(['employee_id' => $employee->id])->save();

    $this->actingAs($user);

    $component = Livewire::test(MyAttendance::class)
        ->call('openOvertimeModal')
        ->set('overtimeDate', ATTENDANCE_TEST_DATE)
        ->set('overtimeStartsAt', '17:00')
        ->set('overtimeEndsAt', '19:00');

    // 17:00–19:00 = 2h, auto-filled while untouched.
    $component->assertSet('overtimeRequestedHours', '2.00')
        ->assertSet('overtimeRequestedHoursTouched', false);

    // Changing the end time keeps re-deriving while untouched.
    $component->set('overtimeEndsAt', '19:30')
        ->assertSet('overtimeRequestedHours', '2.50')
        ->assertSet('overtimeRequestedHoursTouched', false);

    // A manual edit locks the field: later start/end changes leave it alone.
    $component->set('overtimeRequestedHours', '1.50')
        ->assertSet('overtimeRequestedHoursTouched', true)
        ->set('overtimeEndsAt', '21:00')
        ->assertSet('overtimeRequestedHours', '1.50');
});

it('rounds the auto-filled Requested Hours to the quarter-hour step', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $user->forceFill(['employee_id' => $employee->id])->save();

    $this->actingAs($user);

    Livewire::test(MyAttendance::class)
        ->call('openOvertimeModal')
        ->set('overtimeDate', ATTENDANCE_TEST_DATE)
        ->set('overtimeStartsAt', '17:00')
        ->set('overtimeEndsAt', '18:10') // 70 min -> rounds to nearest 0.25h = 1.25
        ->assertSet('overtimeRequestedHours', '1.25');
});

it('auto-fills across the midnight boundary when end precedes start', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $user->forceFill(['employee_id' => $employee->id])->save();

    $this->actingAs($user);

    Livewire::test(MyAttendance::class)
        ->call('openOvertimeModal')
        ->set('overtimeDate', ATTENDANCE_TEST_DATE)
        ->set('overtimeStartsAt', '22:00')
        ->set('overtimeEndsAt', '01:00') // ends <= start -> rolls to next day, 3h
        ->assertSet('overtimeRequestedHours', '3.00');
});

it('lets linked employees submit adjustment requests from the attendance workbench', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $user->forceFill(['employee_id' => $employee->id])->save();

    $this->actingAs($user);

    Livewire::test(MyAttendance::class)
        ->assertSee('My Adjustment Requests')
        ->call('openAdjustmentModal')
        ->set('adjustmentDate', ATTENDANCE_TEST_DATE)
        ->set('adjustmentTime', '08:05')
        ->set('adjustmentEventType', AttendanceClockEvent::TYPE_IN)
        ->set('adjustmentReason', 'Forgot to clock in after network outage.')
        ->call('submitAdjustmentRequest')
        ->assertHasNoErrors();

    $request = AttendanceAdjustmentRequest::query()
        ->where('employee_id', $employee->id)
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request?->status)->toBe(AttendanceAdjustmentRequest::STATUS_SUBMITTED)
        ->and($request?->request_mode)->toBe(AttendanceAdjustmentRequest::MODE_MISSING_PUNCH)
        ->and($request?->target_event_type)->toBe(AttendanceClockEvent::TYPE_IN)
        ->and($request?->reason)->toBe('Forgot to clock in after network outage.');
});

it('lets approvers approve adjustment requests from the approvals workbench', function (): void {
    $approver = createAdminUser();
    $company = Company::query()->findOrFail($approver->company_id);
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $shift = attendanceShiftTemplate($company, ['name' => ATTENDANCE_DAY_SHIFT_NAME]);
    $policyGroup = attendancePolicyGroup($company);
    AttendanceRosterAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_shift_template_id' => $shift->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'effective_from' => '2026-05-01',
        'publish_state' => 'published',
    ]);
    $request = AttendanceAdjustmentRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'request_mode' => AttendanceAdjustmentRequest::MODE_MISSING_PUNCH,
        'target_event_type' => AttendanceClockEvent::TYPE_IN,
        'proposed_occurred_at' => ATTENDANCE_PROPOSED_CLOCK_IN,
        'reason' => 'Forgot to clock in after network outage.',
        'status' => AttendanceAdjustmentRequest::STATUS_SUBMITTED,
        'submitted_by_user_id' => $employee->id,
        'submitted_at' => now(),
    ]);

    $this->actingAs($approver);

    Livewire::test(Approvals::class)
        ->assertSee('Adjustment Queue')
        ->call('approveAdjustment', $request->id)
        ->assertHasNoErrors();

    $request->refresh();
    $clockEvent = AttendanceClockEvent::query()->find($request->applied_clock_event_id);

    expect($request->status)->toBe(AttendanceAdjustmentRequest::STATUS_APPROVED)
        ->and($request->applied_clock_event_id)->not->toBeNull()
        ->and($clockEvent)->not->toBeNull()
        ->and($clockEvent?->event_type)->toBe(AttendanceClockEvent::TYPE_IN)
        ->and($clockEvent?->metadata['adjustment_request_id'] ?? null)->toBe($request->id);
});

it('lets approvers reject adjustment requests from the approvals workbench', function (): void {
    $approver = createAdminUser();
    $company = Company::query()->findOrFail($approver->company_id);
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $request = AttendanceAdjustmentRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'request_mode' => AttendanceAdjustmentRequest::MODE_MISSING_PUNCH,
        'target_event_type' => AttendanceClockEvent::TYPE_OUT,
        'proposed_occurred_at' => '2026-05-13 17:05:00',
        'reason' => 'Missed the clock-out before leaving the floor.',
        'status' => AttendanceAdjustmentRequest::STATUS_SUBMITTED,
        'submitted_by_user_id' => $employee->id,
        'submitted_at' => now(),
    ]);

    $this->actingAs($approver);

    Livewire::test(Approvals::class)
        ->call('rejectAdjustment', $request->id)
        ->assertHasNoErrors();

    $request->refresh();

    expect($request->status)->toBe(AttendanceAdjustmentRequest::STATUS_REJECTED)
        ->and($request->applied_clock_event_id)->toBeNull()
        ->and(AttendanceClockEvent::query()->where('employee_id', $employee->id)->count())->toBe(0);
});
