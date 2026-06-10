<?php

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Leave\CountryPacks\Malaysia\MalaysiaStatutoryLeaveTypes;
use App\Modules\People\Leave\Data\LeaveEncashmentData;
use App\Modules\People\Leave\Data\LeaveLedgerEntryData;
use App\Modules\People\Leave\Data\LeaveLedgerEntryOptions;
use App\Modules\People\Leave\Data\LeaveLedgerEntrySource;
use App\Modules\People\Leave\Data\LeaveLedgerEntrySubject;
use App\Modules\People\Leave\Exceptions\LeaveLedgerImmutableException;
use App\Modules\People\Leave\Exceptions\LeaveRequestValidationException;
use App\Modules\People\Leave\Models\LeaveAssignment;
use App\Modules\People\Leave\Models\LeaveBalanceLedgerEntry;
use App\Modules\People\Leave\Models\LeaveEntitlementPolicy;
use App\Modules\People\Leave\Models\LeaveEntitlementPolicyBand;
use App\Modules\People\Leave\Models\LeaveRequest;
use App\Modules\People\Leave\Models\LeaveRequestPolicy;
use App\Modules\People\Leave\Models\LeaveType;
use App\Modules\People\Leave\Services\ApproveLeaveRequestService;
use App\Modules\People\Leave\Services\CancelLeaveRequestService;
use App\Modules\People\Leave\Services\CarryForwardService;
use App\Modules\People\Leave\Services\LeaveBalanceLedgerService;
use App\Modules\People\Leave\Services\LeaveBalanceStatementBuilder;
use App\Modules\People\Leave\Services\LeaveEncashmentService;
use App\Modules\People\Leave\Services\LeaveNotificationDispatcher;
use App\Modules\People\Leave\Services\SubmitLeaveRequestService;
use App\Modules\People\Leave\Services\WithdrawLeaveRequestService;
use App\Modules\People\Payroll\Models\PayrollCalendar;
use App\Modules\People\Payroll\Models\PayrollInput;
use App\Modules\People\Payroll\Models\PayrollLeaveTypePayItem;
use App\Modules\People\Payroll\Models\PayrollPeriod;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Settings\Models\PeopleNotificationDeliveryLog;

/**
 * @param  array{
 *     companyId: int,
 *     employeeId: int,
 *     leaveTypeId: int,
 *     leaveYear: int,
 *     entryType: string,
 *     quantity: float|int,
 *     unit: string,
 *     sourceType: string,
 *     sourceId?: int|null,
 *     occurredOn?: DateTimeInterface|null
 * }  $attributes
 */
function recordLeaveLedgerEntry(LeaveBalanceLedgerService $ledger, array $attributes): LeaveBalanceLedgerEntry
{
    return $ledger->record(new LeaveLedgerEntryData(
        subject: new LeaveLedgerEntrySubject(
            companyId: $attributes['companyId'],
            employeeId: $attributes['employeeId'],
            leaveTypeId: $attributes['leaveTypeId'],
            leaveYear: $attributes['leaveYear'],
        ),
        entryType: $attributes['entryType'],
        quantity: (float) $attributes['quantity'],
        unit: $attributes['unit'],
        source: new LeaveLedgerEntrySource(
            type: $attributes['sourceType'],
            id: $attributes['sourceId'] ?? null,
        ),
        options: new LeaveLedgerEntryOptions(
            occurredOn: $attributes['occurredOn'] ?? null,
        ),
    ));
}

function createLeaveAssignment(array $overrides = []): array
{
    $company = Company::query()->findOrFail(Company::LICENSEE_ID);
    $employee = Employee::factory()->create(['company_id' => $company->id]);

    $typeAttributes = array_merge([
        'company_id' => $company->id,
        'code' => MalaysiaStatutoryLeaveTypes::CODE_ANNUAL,
        'name' => 'Annual Leave',
        'paid' => true,
        'default_unit' => LeaveType::UNIT_DAY,
        'default_approval_depth' => 1,
        'interacts_with_payroll' => false,
        'compulsory_attachment' => false,
        'status' => LeaveType::STATUS_ACTIVE,
    ], $overrides['leave_type'] ?? []);

    $payItemCode = $typeAttributes['payroll_pay_item_code'] ?? null;
    unset($typeAttributes['payroll_pay_item_code']);

    $type = LeaveType::query()->create($typeAttributes);

    if ($payItemCode !== null) {
        PayrollLeaveTypePayItem::query()->create([
            'company_id' => $company->id,
            'leave_type_id' => $type->id,
            'payroll_pay_item_code' => $payItemCode,
            'effective_from' => '2026-01-01',
        ]);
    }

    $entitlement = LeaveEntitlementPolicy::query()->create([
        'company_id' => $company->id,
        'leave_type_id' => $type->id,
        'code' => 'al_test',
        'name' => 'AL Test',
        'accrual_method' => LeaveEntitlementPolicy::ACCRUAL_ANNUAL_LUMP_NO_PRORATE,
        'entitlement_rounding' => LeaveEntitlementPolicy::ROUNDING_NONE,
        'prorate_for_joiners' => true,
        'prorate_for_leavers' => true,
        'bring_forward_cap_days' => 7,
        'bring_forward_expiry_month' => 3,
        'bring_forward_anchor' => LeaveEntitlementPolicy::ANCHOR_YEAR_START,
        'effective_from' => '2026-01-01',
        'version' => 1,
        'status' => 'active',
    ]);
    LeaveEntitlementPolicyBand::query()->create([
        'leave_entitlement_policy_id' => $entitlement->id,
        'min_years_of_service' => 0,
        'max_years_of_service' => null,
        'entitlement_days' => 21,
    ]);

    $requestPolicy = LeaveRequestPolicy::query()->create(array_merge([
        'company_id' => $company->id,
        'leave_type_id' => $type->id,
        'code' => 'al_request_policy',
        'name' => 'AL Request Policy',
        'allow_negative_balance' => false,
        'include_pending_as_taken' => true,
        'allow_multiple_applications_per_day' => false,
        'no_cross_month_split' => false,
        'compulsory_attachment' => false,
        'exclude_holiday_from_count' => true,
        'exclude_off_day_from_count' => true,
        'exclude_rest_day_from_count' => true,
        'effective_from' => '2026-01-01',
        'version' => 1,
        'status' => 'active',
    ], $overrides['request_policy'] ?? []));

    $assignment = LeaveAssignment::query()->create([
        'company_id' => $company->id,
        'code' => 'al_assignment',
        'name' => 'AL Assignment',
        'leave_type_id' => $type->id,
        'leave_entitlement_policy_id' => $entitlement->id,
        'leave_request_policy_id' => $requestPolicy->id,
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]);
    $assignment->setRelation('leaveType', $type);
    $assignment->setRelation('entitlementPolicy', $entitlement);
    $assignment->setRelation('requestPolicy', $requestPolicy);

    return [$company, $employee, $assignment, $type, $entitlement, $requestPolicy];
}

function expectLeaveValidation(callable $callback, array $expectedCodes): void
{
    try {
        $callback();
        test()->fail('Expected LeaveRequestValidationException to be thrown.');
    } catch (LeaveRequestValidationException $exception) {
        $codes = array_map(fn ($issue) => $issue->code, $exception->issues);

        foreach ($expectedCodes as $code) {
            expect($codes)->toContain($code);
        }
    }
}

function createOpenPayrollRun(Company $company, string $startsOn, string $endsOn): PayrollRun
{
    $calendar = PayrollCalendar::query()->create([
        'company_id' => $company->id,
        'code' => 'leave_test_calendar_'.str_replace('-', '', $startsOn),
        'name' => 'Leave Test Calendar',
        'country_iso' => 'MY',
        'currency' => 'MYR',
        'frequency' => 'monthly',
        'status' => 'active',
    ]);

    $period = PayrollPeriod::query()->create([
        'payroll_calendar_id' => $calendar->id,
        'code' => 'leave_test_period_'.str_replace('-', '', $startsOn),
        'name' => 'Leave Test Period',
        'starts_on' => $startsOn,
        'ends_on' => $endsOn,
        'pay_date' => $endsOn,
        'status' => 'open',
    ]);

    return PayrollRun::query()->create([
        'company_id' => $company->id,
        'payroll_calendar_id' => $calendar->id,
        'payroll_period_id' => $period->id,
        'code' => 'leave_test_run_'.str_replace('-', '', $startsOn),
        'name' => 'Leave Test Run',
        'status' => PayrollRun::STATUS_DRAFT,
        'currency' => 'MYR',
    ]);
}

test('balance ledger is append-only: update throws', function (): void {
    [$company, $employee, , $type] = createLeaveAssignment();

    $entry = recordLeaveLedgerEntry(app(LeaveBalanceLedgerService::class), [
        'companyId' => $company->id,
        'employeeId' => $employee->id,
        'leaveTypeId' => $type->id,
        'leaveYear' => 2026,
        'entryType' => LeaveBalanceLedgerEntry::ENTRY_OPENING,
        'quantity' => 10.0,
        'unit' => 'day',
        'sourceType' => LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
    ]);

    $entry->quantity = 99;
    expect(fn () => $entry->save())->toThrow(LeaveLedgerImmutableException::class);
    expect(fn () => $entry->delete())->toThrow(LeaveLedgerImmutableException::class);
});

test('submit creates a workflow-attached request and writes per-day breakdown', function (): void {
    [$company, $employee, $assignment, $type] = createLeaveAssignment();

    recordLeaveLedgerEntry(app(LeaveBalanceLedgerService::class), [
        'companyId' => $company->id, 'employeeId' => $employee->id, 'leaveTypeId' => $type->id, 'leaveYear' => 2026,
        'entryType' => LeaveBalanceLedgerEntry::ENTRY_OPENING, 'quantity' => 21.0, 'unit' => 'day',
        'sourceType' => LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
        'occurredOn' => new DateTimeImmutable('2026-01-01'),
    ]);

    $request = app(SubmitLeaveRequestService::class)->submit(
        employee: $employee,
        assignment: $assignment,
        startsOn: new DateTimeImmutable('2026-09-14'),
        endsOn: new DateTimeImmutable('2026-09-18'),
        options: ['country_iso' => 'MY'],
    );

    // 14-18 Sep 2026: 16 Sep is Malaysia Day (federal holiday from MY pack); excluded.
    expect($request->status)->toBe(LeaveRequest::STATUS_SUBMITTED)
        ->and((float) $request->quantity)->toBe(4.0)
        ->and($request->flow())->toBe('leave_application');

    expect($request->days()->count())->toBe(5);
    $holidayDay = $request->days
        ->first(fn ($day): bool => $day->occurs_on->format('Y-m-d') === '2026-09-16');
    expect($holidayDay->daytype)->toBe('holiday')
        ->and($holidayDay->counts_against_balance)->toBeFalse();
});

test('submit preview excludes state substitute holidays when a state code is supplied', function (): void {
    [$company, $employee, $assignment, $type] = createLeaveAssignment();

    recordLeaveLedgerEntry(app(LeaveBalanceLedgerService::class), [
        'companyId' => $company->id, 'employeeId' => $employee->id, 'leaveTypeId' => $type->id, 'leaveYear' => 2026,
        'entryType' => LeaveBalanceLedgerEntry::ENTRY_OPENING, 'quantity' => 21.0, 'unit' => 'day',
        'sourceType' => LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
        'occurredOn' => new DateTimeImmutable('2026-01-01'),
    ]);

    $request = app(SubmitLeaveRequestService::class)->submit(
        employee: $employee,
        assignment: $assignment,
        startsOn: new DateTimeImmutable('2026-11-09'),
        endsOn: new DateTimeImmutable('2026-11-10'),
        options: ['country_iso' => 'MY', 'state_code' => 'KL'],
    );

    expect((float) $request->quantity)->toBe(1.0);

    $holidayDay = $request->days
        ->first(fn ($day): bool => $day->occurs_on->format('Y-m-d') === '2026-11-09');

    expect($holidayDay?->daytype)->toBe('holiday')
        ->and($holidayDay?->counts_against_balance)->toBeFalse();
});

test('submit rejects when balance is insufficient and policy disallows negative balance', function (): void {
    [, $employee, $assignment] = createLeaveAssignment();

    expectLeaveValidation(fn () => app(SubmitLeaveRequestService::class)->submit(
        employee: $employee,
        assignment: $assignment,
        startsOn: new DateTimeImmutable('2026-09-14'),
        endsOn: new DateTimeImmutable('2026-09-18'),
        options: ['country_iso' => 'MY'],
    ), ['insufficient_balance']);
});

test('submit rejects when compulsory attachment is missing', function (): void {
    [$company, $employee, $assignment, $type] = createLeaveAssignment([
        'leave_type' => ['code' => 'sick_leave', 'name' => 'Sick Leave', 'compulsory_attachment' => true],
    ]);
    recordLeaveLedgerEntry(app(LeaveBalanceLedgerService::class), [
        'companyId' => $company->id, 'employeeId' => $employee->id, 'leaveTypeId' => $type->id, 'leaveYear' => 2026,
        'entryType' => LeaveBalanceLedgerEntry::ENTRY_OPENING, 'quantity' => 14, 'unit' => 'day',
        'sourceType' => LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
    ]);

    expectLeaveValidation(fn () => app(SubmitLeaveRequestService::class)->submit(
        employee: $employee,
        assignment: $assignment,
        startsOn: new DateTimeImmutable('2026-09-14'),
        endsOn: new DateTimeImmutable('2026-09-14'),
        options: ['country_iso' => 'MY', 'attachment_count' => 0],
    ), ['attachment_required']);
});

test('submit rejects when request crosses a month boundary and policy forbids it', function (): void {
    [$company, $employee, $assignment, $type] = createLeaveAssignment([
        'request_policy' => ['no_cross_month_split' => true],
    ]);

    recordLeaveLedgerEntry(app(LeaveBalanceLedgerService::class), [
        'companyId' => $company->id, 'employeeId' => $employee->id, 'leaveTypeId' => $type->id, 'leaveYear' => 2026,
        'entryType' => LeaveBalanceLedgerEntry::ENTRY_OPENING, 'quantity' => 21, 'unit' => 'day',
        'sourceType' => LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
    ]);

    expectLeaveValidation(fn () => app(SubmitLeaveRequestService::class)->submit(
        employee: $employee,
        assignment: $assignment,
        startsOn: new DateTimeImmutable('2026-10-31'),
        endsOn: new DateTimeImmutable('2026-11-02'),
        options: ['country_iso' => 'MY'],
    ), ['cross_month_split_not_allowed']);
});

test('submit rejects back-dated requests outside the configured back-date window', function (): void {
    [$company, $employee, $assignment, $type] = createLeaveAssignment([
        'request_policy' => [
            'back_date' => [
                'allowed' => true,
                'max_days' => 2,
                'tag' => 'LATE SUBMISSION',
            ],
        ],
    ]);

    recordLeaveLedgerEntry(app(LeaveBalanceLedgerService::class), [
        'companyId' => $company->id, 'employeeId' => $employee->id, 'leaveTypeId' => $type->id, 'leaveYear' => 2026,
        'entryType' => LeaveBalanceLedgerEntry::ENTRY_OPENING, 'quantity' => 21, 'unit' => 'day',
        'sourceType' => LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
    ]);

    expectLeaveValidation(fn () => app(SubmitLeaveRequestService::class)->submit(
        employee: $employee,
        assignment: $assignment,
        startsOn: new DateTimeImmutable('today - 5 days'),
        endsOn: new DateTimeImmutable('today - 5 days'),
        options: ['country_iso' => 'MY'],
    ), ['back_date_window_exceeded']);
});

test('submit requires short-notice handling when advance notice is missed', function (): void {
    [$company, $employee, $assignment, $type] = createLeaveAssignment([
        'request_policy' => [
            'advance_notice' => [
                'standard_days' => 5,
                'short_notice' => [
                    'allowed' => true,
                    'tag' => 'EMERGENCY LEAVE',
                    'annual_cap' => 2,
                    'disallow_today' => true,
                ],
            ],
        ],
    ]);

    recordLeaveLedgerEntry(app(LeaveBalanceLedgerService::class), [
        'companyId' => $company->id, 'employeeId' => $employee->id, 'leaveTypeId' => $type->id, 'leaveYear' => 2026,
        'entryType' => LeaveBalanceLedgerEntry::ENTRY_OPENING, 'quantity' => 21, 'unit' => 'day',
        'sourceType' => LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
    ]);

    expectLeaveValidation(fn () => app(SubmitLeaveRequestService::class)->submit(
        employee: $employee,
        assignment: $assignment,
        startsOn: new DateTimeImmutable('today + 2 days'),
        endsOn: new DateTimeImmutable('today + 2 days'),
        options: ['country_iso' => 'MY'],
    ), ['advance_notice_required']);

    $request = app(SubmitLeaveRequestService::class)->submit(
        employee: $employee,
        assignment: $assignment,
        startsOn: new DateTimeImmutable('today + 2 days'),
        endsOn: new DateTimeImmutable('today + 2 days'),
        options: ['country_iso' => 'MY', 'short_notice' => true],
    );

    expect($request->short_notice)->toBeTrue()
        ->and($request->emergency_tag)->toBe('EMERGENCY LEAVE');
});

test('submit rejects overlapping active leave when multiple applications per day are disabled', function (): void {
    [$company, $employee, $assignment, $type] = createLeaveAssignment();

    recordLeaveLedgerEntry(app(LeaveBalanceLedgerService::class), [
        'companyId' => $company->id, 'employeeId' => $employee->id, 'leaveTypeId' => $type->id, 'leaveYear' => 2026,
        'entryType' => LeaveBalanceLedgerEntry::ENTRY_OPENING, 'quantity' => 21, 'unit' => 'day',
        'sourceType' => LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
    ]);

    app(SubmitLeaveRequestService::class)->submit(
        employee: $employee,
        assignment: $assignment,
        startsOn: new DateTimeImmutable('2026-11-03'),
        endsOn: new DateTimeImmutable('2026-11-05'),
        options: ['country_iso' => 'MY'],
    );

    expectLeaveValidation(fn () => app(SubmitLeaveRequestService::class)->submit(
        employee: $employee,
        assignment: $assignment,
        startsOn: new DateTimeImmutable('2026-11-05'),
        endsOn: new DateTimeImmutable('2026-11-06'),
        options: ['country_iso' => 'MY'],
    ), ['overlapping_request']);
});

test('submit treats pending requests as encumbered balance when policy enables it', function (): void {
    [$company, $employee, $assignment, $type] = createLeaveAssignment();

    recordLeaveLedgerEntry(app(LeaveBalanceLedgerService::class), [
        'companyId' => $company->id, 'employeeId' => $employee->id, 'leaveTypeId' => $type->id, 'leaveYear' => 2026,
        'entryType' => LeaveBalanceLedgerEntry::ENTRY_OPENING, 'quantity' => 5, 'unit' => 'day',
        'sourceType' => LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
    ]);

    app(SubmitLeaveRequestService::class)->submit(
        employee: $employee,
        assignment: $assignment,
        startsOn: new DateTimeImmutable('2026-10-13'),
        endsOn: new DateTimeImmutable('2026-10-15'),
        options: ['country_iso' => 'MY'],
    );

    expectLeaveValidation(fn () => app(SubmitLeaveRequestService::class)->submit(
        employee: $employee,
        assignment: $assignment,
        startsOn: new DateTimeImmutable('2026-10-16'),
        endsOn: new DateTimeImmutable('2026-10-18'),
        options: ['country_iso' => 'MY'],
    ), ['insufficient_balance']);
});

test('approve auto-applies, writes taken ledger entry and decreases balance', function (): void {
    [$company, $employee, $assignment, $type] = createLeaveAssignment();
    $ledger = app(LeaveBalanceLedgerService::class);

    recordLeaveLedgerEntry($ledger, [
        'companyId' => $company->id, 'employeeId' => $employee->id, 'leaveTypeId' => $type->id, 'leaveYear' => 2026,
        'entryType' => LeaveBalanceLedgerEntry::ENTRY_OPENING, 'quantity' => 21, 'unit' => 'day',
        'sourceType' => LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
    ]);
    expect($ledger->balanceFor($employee->id, $type->id, 2026))->toBe(21.0);

    $request = app(SubmitLeaveRequestService::class)->submit(
        employee: $employee, assignment: $assignment,
        startsOn: new DateTimeImmutable('2026-10-05'),
        endsOn: new DateTimeImmutable('2026-10-09'),
        options: ['country_iso' => 'MY'],
    );
    $applied = app(ApproveLeaveRequestService::class)->approve($request, autoApply: true);

    expect($applied->status)->toBe(LeaveRequest::STATUS_APPLIED)
        ->and($applied->applied_ledger_entry_id)->not->toBeNull()
        ->and($ledger->balanceFor($employee->id, $type->id, 2026))->toBe(16.0);

    $subjects = PeopleNotificationDeliveryLog::query()
        ->where('notifiable_type', LeaveRequest::class)
        ->where('notifiable_id', $request->id)
        ->pluck('subject')
        ->all();

    expect($subjects)->toContain(LeaveNotificationDispatcher::EVENT_APPROVED)
        ->and($subjects)->toContain(LeaveNotificationDispatcher::EVENT_APPLIED);
});

test('withdrawing an applied request writes a reversing cancelled entry restoring balance', function (): void {
    [$company, $employee, $assignment, $type] = createLeaveAssignment();
    $ledger = app(LeaveBalanceLedgerService::class);

    recordLeaveLedgerEntry($ledger, [
        'companyId' => $company->id, 'employeeId' => $employee->id, 'leaveTypeId' => $type->id, 'leaveYear' => 2026,
        'entryType' => LeaveBalanceLedgerEntry::ENTRY_OPENING, 'quantity' => 21, 'unit' => 'day',
        'sourceType' => LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
    ]);

    $request = app(SubmitLeaveRequestService::class)->submit(
        employee: $employee, assignment: $assignment,
        startsOn: new DateTimeImmutable('2026-10-05'),
        endsOn: new DateTimeImmutable('2026-10-07'),
        options: ['country_iso' => 'MY'],
    );
    app(ApproveLeaveRequestService::class)->approve($request, autoApply: true);

    expect($ledger->balanceFor($employee->id, $type->id, 2026))->toBe(18.0);

    $withdrawn = app(WithdrawLeaveRequestService::class)->withdraw($request->refresh(), reason: 'plans changed');

    expect($withdrawn->status)->toBe(LeaveRequest::STATUS_WITHDRAWN)
        ->and($ledger->balanceFor($employee->id, $type->id, 2026))->toBe(21.0);

    // Original 'taken' entry must not have been mutated — append-only.
    expect(LeaveBalanceLedgerEntry::query()
        ->where('employee_id', $employee->id)
        ->where('entry_type', LeaveBalanceLedgerEntry::ENTRY_CANCELLED)
        ->count())->toBe(1);
});

test('cancelling a submitted request transitions to cancelled with audit event', function (): void {
    [$company, $employee, $assignment, $type] = createLeaveAssignment();
    recordLeaveLedgerEntry(app(LeaveBalanceLedgerService::class), [
        'companyId' => $company->id, 'employeeId' => $employee->id, 'leaveTypeId' => $type->id, 'leaveYear' => 2026,
        'entryType' => LeaveBalanceLedgerEntry::ENTRY_OPENING, 'quantity' => 21, 'unit' => 'day',
        'sourceType' => LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
    ]);

    $request = app(SubmitLeaveRequestService::class)->submit(
        employee: $employee, assignment: $assignment,
        startsOn: new DateTimeImmutable('2026-10-05'),
        endsOn: new DateTimeImmutable('2026-10-07'),
        options: ['country_iso' => 'MY'],
    );

    $cancelled = app(CancelLeaveRequestService::class)->cancel($request, reason: 'mistake');

    expect($cancelled->status)->toBe(LeaveRequest::STATUS_CANCELLED)
        ->and($cancelled->cancelled_at)->not->toBeNull();
});

test('unpaid leave generates a PayrollInput row on apply, deduplicated by leave request id', function (): void {
    [$company, $employee, $assignment] = createLeaveAssignment([
        'leave_type' => [
            'code' => MalaysiaStatutoryLeaveTypes::CODE_UNPAID,
            'name' => 'Unpaid Leave',
            'paid' => false,
            'interacts_with_payroll' => true,
            'payroll_pay_item_code' => LeaveType::PAYROLL_CODE_UNPAID_LEAVE,
        ],
        'request_policy' => ['allow_negative_balance' => true],
    ]);
    createOpenPayrollRun($company, '2026-10-01', '2026-10-31');

    $request = app(SubmitLeaveRequestService::class)->submit(
        employee: $employee, assignment: $assignment,
        startsOn: new DateTimeImmutable('2026-10-05'),
        endsOn: new DateTimeImmutable('2026-10-07'),
        options: ['country_iso' => 'MY'],
    );
    app(ApproveLeaveRequestService::class)->approve($request, autoApply: true);

    $inputs = PayrollInput::query()
        ->where('source_type', 'leave_request')
        ->where('source_id', $request->id)
        ->get();

    expect($inputs)->toHaveCount(1)
        ->and($inputs->first()->pay_item_code)->toBe(LeaveType::PAYROLL_CODE_UNPAID_LEAVE)
        ->and((float) $inputs->first()->quantity)->toBe(3.0);
});

test('carry-forward applies cap and writes carried_forward + expired ledger entries', function (): void {
    [$company, $employee, , $type, $entitlement] = createLeaveAssignment();
    $ledger = app(LeaveBalanceLedgerService::class);

    recordLeaveLedgerEntry($ledger, [
        'companyId' => $company->id, 'employeeId' => $employee->id, 'leaveTypeId' => $type->id, 'leaveYear' => 2025,
        'entryType' => LeaveBalanceLedgerEntry::ENTRY_OPENING, 'quantity' => 10.5, 'unit' => 'day',
        'sourceType' => LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
        'occurredOn' => new DateTimeImmutable('2025-01-01'),
    ]);

    $outcome = app(CarryForwardService::class)->compute(
        companyId: $company->id,
        employeeId: $employee->id,
        leaveTypeId: $type->id,
        fromYear: 2025,
        policy: $entitlement,
        dryRun: false,
    );

    expect($outcome->carriedForward)->toBe(7.0)
        ->and($outcome->expiredAtYearEnd)->toBe(3.5)
        ->and($outcome->toYear)->toBe(2026)
        ->and($outcome->expiryMonth)->toBe(3)
        ->and($ledger->balanceFor($employee->id, $type->id, 2025))->toBe(7.0) // 10.5 - 3.5
        ->and($ledger->balanceFor($employee->id, $type->id, 2026))->toBe(7.0);
});

test('encashment debits the ledger and creates a PayrollInput earning line', function (): void {
    [$company, $employee, , $type] = createLeaveAssignment();
    $ledger = app(LeaveBalanceLedgerService::class);
    $today = now();
    createOpenPayrollRun($company, $today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString());

    recordLeaveLedgerEntry($ledger, [
        'companyId' => $company->id, 'employeeId' => $employee->id, 'leaveTypeId' => $type->id, 'leaveYear' => 2026,
        'entryType' => LeaveBalanceLedgerEntry::ENTRY_OPENING, 'quantity' => 10, 'unit' => 'day',
        'sourceType' => LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
    ]);

    $entry = app(LeaveEncashmentService::class)->encash(new LeaveEncashmentData(
        companyId: $company->id,
        employeeId: $employee->id,
        leaveTypeId: $type->id,
        leaveYear: 2026,
        days: 4,
    ));

    expect($entry->entry_type)->toBe(LeaveBalanceLedgerEntry::ENTRY_ENCASHED)
        ->and($ledger->balanceFor($employee->id, $type->id, 2026))->toBe(6.0);

    $input = PayrollInput::query()->where('source_type', 'leave_encashment')->where('source_id', $entry->id)->first();
    expect($input)->not->toBeNull()
        ->and($input->pay_item_code)->toBe(LeaveType::PAYROLL_CODE_LEAVE_ENCASHMENT)
        ->and($input->input_type)->toBe(PayrollInput::TYPE_EARNING);
});

test('balance statement aggregates ledger entries per leave type', function (): void {
    [$company, $employee, , $type] = createLeaveAssignment();
    $ledger = app(LeaveBalanceLedgerService::class);
    recordLeaveLedgerEntry($ledger, [
        'companyId' => $company->id, 'employeeId' => $employee->id, 'leaveTypeId' => $type->id, 'leaveYear' => 2026,
        'entryType' => LeaveBalanceLedgerEntry::ENTRY_OPENING, 'quantity' => 21, 'unit' => 'day',
        'sourceType' => LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
    ]);
    recordLeaveLedgerEntry($ledger, [
        'companyId' => $company->id, 'employeeId' => $employee->id, 'leaveTypeId' => $type->id, 'leaveYear' => 2026,
        'entryType' => LeaveBalanceLedgerEntry::ENTRY_TAKEN, 'quantity' => -4, 'unit' => 'day',
        'sourceType' => LeaveBalanceLedgerEntry::SOURCE_LEAVE_REQUEST,
    ]);

    $stmt = app(LeaveBalanceStatementBuilder::class)->build($employee->id, 2026);

    expect($stmt->rows)->toHaveCount(1);
    $row = $stmt->rows[0];
    expect($row->opening)->toBe(21.0)
        ->and($row->taken)->toBe(4.0)
        ->and($row->balance)->toBe(17.0);
});

test('seed-sbg-pack command is idempotent and creates the expected cohort assignments', function (): void {
    $code = $this->artisan('blb:leave:seed-sbg-pack')->run();
    expect($code)->toBe(0);
    $firstRun = LeaveAssignment::query()->count();

    $this->artisan('blb:leave:seed-sbg-pack')->run();
    expect(LeaveAssignment::query()->count())->toBe($firstRun);

    $codes = LeaveAssignment::query()->pluck('code')->all();
    foreach (['sbg_fm_', 'sbg_fw_', 'sbg_mm_', 'sbg_single_'] as $cohort) {
        expect(collect($codes)->some(fn ($c) => str_starts_with($c, $cohort)))
            ->toBeTrue(sprintf('Expected at least one assignment for cohort prefix %s', $cohort));
    }
});
