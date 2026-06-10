<?php

/**
 * Contract test for the allowance-materialisation seam (plan 12 Phase 3).
 *
 * Materialisation itself is not yet implemented — allowance rules exist
 * today as definitions consumed by simulation only. When a real
 * materialisation path lands, it dispatches AttendanceAllowanceMaterialized
 * and the listener wired in this test must continue producing a
 * PayrollInput via the intake. This test fakes that producer by
 * dispatching the event directly and asserts the downstream path is
 * intact.
 */

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Attendance\Events\AttendanceAllowanceMaterialized;
use App\Modules\People\Attendance\Models\AttendanceAllowanceRule;
use App\Modules\People\Payroll\Models\PayrollAttendanceRulePayItem;
use App\Modules\People\Payroll\Models\PayrollCalendar;
use App\Modules\People\Payroll\Models\PayrollInput;
use App\Modules\People\Payroll\Models\PayrollPeriod;
use App\Modules\People\Payroll\Models\PayrollRun;

it('materialises a PayrollInput from an AttendanceAllowanceMaterialized event', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);

    $rule = AttendanceAllowanceRule::query()->create([
        'company_id' => $company->id,
        'code' => 'MEAL_ALLOW',
        'name' => 'Meal allowance',
        'allowance_type' => AttendanceAllowanceRule::TYPE_DAILY,
        'resolution_method' => AttendanceAllowanceRule::RESOLUTION_SUM,
        'condition_rows' => [
            ['description' => 'Always pay', 'amount' => 15, 'predicate' => []],
        ],
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]);

    PayrollAttendanceRulePayItem::query()->create([
        'company_id' => $company->id,
        'attendance_allowance_rule_id' => $rule->id,
        'payroll_pay_item_code' => 'MEAL_ALLOW_SHIFT',
        'effective_from' => '2026-01-01',
    ]);

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
    PayrollRun::query()->create([
        'company_id' => $company->id,
        'payroll_calendar_id' => $calendar->id,
        'payroll_period_id' => $period->id,
        'code' => 'MAY-2026',
        'name' => 'May 2026',
        'status' => PayrollRun::STATUS_DRAFT,
        'currency' => 'MYR',
    ]);

    event(new AttendanceAllowanceMaterialized(
        companyId: (int) $company->id,
        employeeId: (int) $employee->id,
        attendanceAllowanceRuleId: (int) $rule->id,
        occurredOn: new DateTimeImmutable('2026-05-13'),
        amount: 15.00,
    ));

    expect(PayrollInput::query()->count())->toBe(1)
        ->and(PayrollInput::query()->first()?->pay_item_code)->toBe('MEAL_ALLOW_SHIFT')
        ->and((float) PayrollInput::query()->first()?->amount)->toBe(15.0)
        ->and(PayrollInput::query()->first()?->source_type)->toBe('attendance_allowance_rule');
});

it('skips the contribution when no pay-item mapping exists for the rule', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);

    $rule = AttendanceAllowanceRule::query()->create([
        'company_id' => $company->id,
        'code' => 'NO_MAP',
        'name' => 'Unmapped rule',
        'allowance_type' => AttendanceAllowanceRule::TYPE_DAILY,
        'resolution_method' => AttendanceAllowanceRule::RESOLUTION_SUM,
        'condition_rows' => [['description' => '', 'amount' => 10, 'predicate' => []]],
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]);

    event(new AttendanceAllowanceMaterialized(
        companyId: (int) $company->id,
        employeeId: (int) $employee->id,
        attendanceAllowanceRuleId: (int) $rule->id,
        occurredOn: new DateTimeImmutable('2026-05-13'),
        amount: 10.00,
    ));

    expect(PayrollInput::query()->count())->toBe(0);
});
