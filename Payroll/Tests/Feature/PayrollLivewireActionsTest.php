<?php

use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Attendance\Models\AttendanceAllowanceRule;
use App\Domains\People\Claim\Models\ClaimType;
use App\Domains\People\Leave\Models\LeaveType;
use App\Domains\People\Payroll\Livewire\AttendanceAllowanceMapping;
use App\Domains\People\Payroll\Livewire\ClaimTypePayItemMapping;
use App\Domains\People\Payroll\Livewire\Index as PayrollIndex;
use App\Domains\People\Payroll\Livewire\LeaveTypePayItemMapping;
use App\Domains\People\Payroll\Models\PayrollAttendanceRulePayItem;
use App\Domains\People\Payroll\Models\PayrollCalendar;
use App\Domains\People\Payroll\Models\PayrollClaimTypePayItem;
use App\Domains\People\Payroll\Models\PayrollInput;
use App\Domains\People\Payroll\Models\PayrollLeaveTypePayItem;
use App\Domains\People\Payroll\Models\PayrollPayItem;
use App\Domains\People\Payroll\Models\PayrollPayItemClassification;
use App\Domains\People\Payroll\Models\PayrollPeriod;
use App\Domains\People\Payroll\Models\PayrollRun;
use App\Domains\People\Payroll\Models\PayrollRunParticipant;
use App\Domains\People\Payroll\Models\PayrollStatutoryRuleRow;
use App\Domains\People\Payroll\Models\PayrollStatutoryRuleSet;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Branch-level sweep notes (#61): wired `transitionPayrollRun` literals are review,
 * approve, close, and void — all four are driven below. Defensive early returns
 * such as `setTab` with an unknown tab are excluded (no collaborator/write).
 * Mapping components only wire startEditing, saveMapping, cancelEditing, and
 * deleteMapping; saveMapping and deleteMapping were covered in #60.
 */
const PAYROLL_WORKBENCH_MAPPING_EFFECTIVE_FROM = '2026-06-01';

const PAYROLL_WORKBENCH_CANCEL_EDITING_TODAY = '2026-03-15';

/**
 * @return array{admin: User, run: PayrollRun}
 */
function payrollWorkbenchRunFixtures(): array
{
    $admin = createAdminUser();
    $companyId = (int) $admin->company_id;
    $employee = Employee::factory()->create(['company_id' => $companyId]);
    $calendar = PayrollCalendar::query()->create([
        'company_id' => $companyId,
        'code' => 'MONTHLY-WB',
        'name' => 'Monthly workbench',
        'country_iso' => 'MY',
        'currency' => 'MYR',
        'frequency' => 'monthly',
        'status' => 'active',
    ]);
    $period = PayrollPeriod::query()->create([
        'payroll_calendar_id' => $calendar->id,
        'code' => '2026-01',
        'name' => 'January 2026',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-01-31',
        'pay_date' => '2026-01-31',
        'status' => 'open',
    ]);
    $run = PayrollRun::query()->create([
        'company_id' => $companyId,
        'payroll_calendar_id' => $calendar->id,
        'payroll_period_id' => $period->id,
        'code' => 'WB-2026-01',
        'name' => 'January workbench run',
        'status' => PayrollRun::STATUS_DRAFT,
        'currency' => 'MYR',
    ]);
    $participant = PayrollRunParticipant::query()->create([
        'payroll_run_id' => $run->id,
        'company_id' => $companyId,
        'employee_id' => $employee->id,
        'status' => 'included',
        'currency' => 'MYR',
    ]);
    PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'pay_item_code' => 'basic_salary',
        'label' => 'Basic Salary',
        'input_type' => PayrollInput::TYPE_EARNING,
        'amount' => '2500.0000',
        'currency' => 'MYR',
    ]);

    return ['admin' => $admin, 'run' => $run];
}

function payrollWorkbenchPayItem(int $companyId, string $code): PayrollPayItem
{
    return PayrollPayItem::query()->create([
        'company_id' => $companyId,
        'code' => $code,
        'name' => strtoupper($code),
        'input_type' => PayrollInput::TYPE_EARNING,
        'status' => 'active',
    ]);
}

test('payroll index navigation actions update workbench state', function (): void {
    ['admin' => $admin, 'run' => $run] = payrollWorkbenchRunFixtures();

    Livewire::actingAs($admin)
        ->test(PayrollIndex::class)
        ->call('setTab', 'pay-items')
        ->assertSet('tab', 'pay-items')
        ->call('selectRun', $run->id)
        ->assertSet('selectedRunId', $run->id)
        ->assertSet('tab', 'runs')
        ->set('search', 'january')
        ->assertHasNoErrors();
});

test('payroll index createPayItem persists a normalized pay item', function (): void {
    $admin = createAdminUser();
    $companyId = (int) $admin->company_id;

    Livewire::actingAs($admin)
        ->test(PayrollIndex::class)
        ->set('payItemCode', 'Meal Allowance')
        ->set('payItemName', 'Meal Allowance')
        ->set('payItemInputType', PayrollInput::TYPE_EARNING)
        ->call('createPayItem')
        ->assertHasNoErrors();

    $payItem = PayrollPayItem::query()->where('company_id', $companyId)->where('code', 'meal_allowance')->first();
    expect($payItem)->not->toBeNull()
        ->and($payItem->name)->toBe('Meal Allowance');
});

test('payroll index createClassification upserts classification rows', function (): void {
    $admin = createAdminUser();
    $companyId = (int) $admin->company_id;
    $payItem = payrollWorkbenchPayItem($companyId, 'transport');

    Livewire::actingAs($admin)
        ->test(PayrollIndex::class)
        ->set('classificationPayItemId', (string) $payItem->id)
        ->set('classificationCountryIso', 'MY')
        ->set('classificationKey', 'statutory_wage_base')
        ->set('classificationValue', 'included')
        ->set('classificationEffectiveFrom', '2026-06-01')
        ->set('classificationSourcePack', 'belimbing/payroll-my')
        ->set('classificationSourceVersion', '2026.dev')
        ->call('createClassification')
        ->assertHasNoErrors()
        ->set('classificationValue', 'excluded')
        ->call('createClassification')
        ->assertHasNoErrors();

    $rows = PayrollPayItemClassification::query()->where('payroll_pay_item_id', $payItem->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->classification_value)->toBe('excluded');
});

test('payroll index createRuleRow appends a statutory rule row', function (): void {
    $admin = createAdminUser();

    $ruleSet = PayrollStatutoryRuleSet::query()->create([
        'country_iso' => 'MY',
        'rule_key' => 'epf_wb',
        'name' => 'EPF workbench',
        'source_pack' => 'belimbing/payroll-my',
        'source_version' => '2026.dev',
        'effective_from' => '2026-01-01',
        'rounding_policy' => ['mode' => 'ceiling', 'precision' => '0.01'],
    ]);

    Livewire::actingAs($admin)
        ->test(PayrollIndex::class)
        ->set('ruleRowRuleSetId', (string) $ruleSet->id)
        ->set('ruleRowKey', 'standard')
        ->set('ruleRowEmployeeRate', '0.11')
        ->set('ruleRowEmployerRate', '0.13')
        ->call('createRuleRow')
        ->assertHasNoErrors();

    $row = PayrollStatutoryRuleRow::query()->where('payroll_statutory_rule_set_id', $ruleSet->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->row_key)->toBe('standard')
        ->and((string) $row->employee_rate)->toBe('0.11000000');
});

test('payroll index calculateRun and transitionPayrollRun drive full finalization lifecycle', function (): void {
    ['admin' => $admin, 'run' => $run] = payrollWorkbenchRunFixtures();

    $component = Livewire::actingAs($admin)->test(PayrollIndex::class);

    $component->call('calculateRun', $run->id)->assertHasNoErrors();
    expect($run->refresh()->status)->toBe(PayrollRun::STATUS_CALCULATED);

    $component->call('transitionPayrollRun', 'review', $run->id)->assertHasNoErrors();
    expect($run->refresh()->status)->toBe(PayrollRun::STATUS_REVIEWED);

    $component->call('transitionPayrollRun', 'approve', $run->id)->assertHasNoErrors();
    expect($run->refresh()->status)->toBe(PayrollRun::STATUS_APPROVED);

    $component->call('transitionPayrollRun', 'close', $run->id)->assertHasNoErrors();
    expect($run->refresh()->status)->toBe(PayrollRun::STATUS_CLOSED);
});

test('payroll index transitionPayrollRun void marks a run voided', function (): void {
    ['admin' => $admin, 'run' => $run] = payrollWorkbenchRunFixtures();

    Livewire::actingAs($admin)
        ->test(PayrollIndex::class)
        ->call('transitionPayrollRun', 'void', $run->id)
        ->assertHasNoErrors();

    expect($run->refresh()->status)->toBe(PayrollRun::STATUS_VOIDED);
});

test('payroll index transitionPayrollRun approve refuses a run with no installed country pack', function (): void {
    $admin = createAdminUser();
    $companyId = (int) $admin->company_id;
    $calendar = PayrollCalendar::query()->create([
        'company_id' => $companyId,
        'code' => 'SG-MONTHLY',
        'name' => 'Singapore monthly',
        'country_iso' => 'SG',
        'currency' => 'SGD',
        'frequency' => 'monthly',
        'status' => 'active',
    ]);
    $period = PayrollPeriod::query()->create([
        'payroll_calendar_id' => $calendar->id,
        'code' => '2026-01',
        'name' => 'January 2026',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-01-31',
        'pay_date' => '2026-01-31',
        'status' => 'open',
    ]);
    $run = PayrollRun::query()->create([
        'company_id' => $companyId,
        'payroll_calendar_id' => $calendar->id,
        'payroll_period_id' => $period->id,
        'code' => 'SG-WB-2026-01',
        'name' => 'Singapore workbench run',
        'status' => PayrollRun::STATUS_REVIEWED,
        'currency' => 'SGD',
    ]);

    Livewire::actingAs($admin)
        ->test(PayrollIndex::class)
        ->call('transitionPayrollRun', 'approve', $run->id)
        ->assertHasNoErrors();

    expect($run->refresh()->status)->toBe(PayrollRun::STATUS_REVIEWED);
});

test('payroll index transitionPayrollRun close refuses a run with no installed country pack', function (): void {
    $admin = createAdminUser();
    $companyId = (int) $admin->company_id;
    $calendar = PayrollCalendar::query()->create([
        'company_id' => $companyId,
        'code' => 'SG-MONTHLY-CLOSE',
        'name' => 'Singapore monthly close guard',
        'country_iso' => 'SG',
        'currency' => 'SGD',
        'frequency' => 'monthly',
        'status' => 'active',
    ]);
    $period = PayrollPeriod::query()->create([
        'payroll_calendar_id' => $calendar->id,
        'code' => '2026-02',
        'name' => 'February 2026',
        'starts_on' => '2026-02-01',
        'ends_on' => '2026-02-28',
        'pay_date' => '2026-02-28',
        'status' => 'open',
    ]);
    $run = PayrollRun::query()->create([
        'company_id' => $companyId,
        'payroll_calendar_id' => $calendar->id,
        'payroll_period_id' => $period->id,
        'code' => 'SG-WB-2026-02',
        'name' => 'Singapore workbench run for close guard',
        'status' => PayrollRun::STATUS_APPROVED,
        'currency' => 'SGD',
    ]);

    Livewire::actingAs($admin)
        ->test(PayrollIndex::class)
        ->call('transitionPayrollRun', 'close', $run->id)
        ->assertHasNoErrors();

    expect($run->refresh()->status)->toBe(PayrollRun::STATUS_APPROVED);
});

test('attendance allowance mapping cancelEditing clears edit state', function (): void {
    Carbon::setTestNow(PAYROLL_WORKBENCH_CANCEL_EDITING_TODAY);

    $admin = createAdminUser();
    $companyId = (int) $admin->company_id;
    payrollWorkbenchPayItem($companyId, 'meal_allowance');
    $rule = AttendanceAllowanceRule::query()->create([
        'company_id' => $companyId,
        'code' => 'MEAL_ALLOW',
        'name' => 'Meal allowance',
        'allowance_type' => AttendanceAllowanceRule::TYPE_DAILY,
        'resolution_method' => AttendanceAllowanceRule::RESOLUTION_SUM,
        'condition_rows' => [['description' => 'Always pay', 'amount' => 15, 'predicate' => []]],
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]);
    PayrollAttendanceRulePayItem::query()->create([
        'company_id' => $companyId,
        'attendance_allowance_rule_id' => $rule->id,
        'payroll_pay_item_code' => 'meal_allowance',
        'effective_from' => PAYROLL_WORKBENCH_MAPPING_EFFECTIVE_FROM,
        'effective_to' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(AttendanceAllowanceMapping::class)
        ->call('startEditing', $rule->id)
        ->assertSet('editingRuleId', $rule->id)
        ->assertSet('editingEffectiveFrom', PAYROLL_WORKBENCH_MAPPING_EFFECTIVE_FROM)
        ->set('editingPayItemCode', 'meal_allowance')
        ->call('cancelEditing')
        ->assertSet('editingRuleId', 0)
        ->assertSet('editingPayItemCode', '')
        ->assertSet('editingEffectiveFrom', PAYROLL_WORKBENCH_CANCEL_EDITING_TODAY);
});

test('attendance allowance mapping deleteMapping removes the dated row', function (): void {
    $admin = createAdminUser();
    $companyId = (int) $admin->company_id;
    payrollWorkbenchPayItem($companyId, 'meal_allowance');
    $rule = AttendanceAllowanceRule::query()->create([
        'company_id' => $companyId,
        'code' => 'MEAL_ALLOW',
        'name' => 'Meal allowance',
        'allowance_type' => AttendanceAllowanceRule::TYPE_DAILY,
        'resolution_method' => AttendanceAllowanceRule::RESOLUTION_SUM,
        'condition_rows' => [['description' => 'Always pay', 'amount' => 15, 'predicate' => []]],
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]);

    PayrollAttendanceRulePayItem::query()->create([
        'company_id' => $companyId,
        'attendance_allowance_rule_id' => $rule->id,
        'payroll_pay_item_code' => 'meal_allowance',
        'effective_from' => '2026-06-01',
        'effective_to' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(AttendanceAllowanceMapping::class)
        ->call('deleteMapping', $rule->id, '2026-06-01')
        ->assertHasNoErrors();

    expect(PayrollAttendanceRulePayItem::query()->where('attendance_allowance_rule_id', $rule->id)->count())->toBe(0);
});

test('claim type mapping cancelEditing clears edit state', function (): void {
    Carbon::setTestNow(PAYROLL_WORKBENCH_CANCEL_EDITING_TODAY);

    $admin = createAdminUser();
    $companyId = (int) $admin->company_id;
    payrollWorkbenchPayItem($companyId, 'claim_medical');
    $type = ClaimType::query()->create([
        'company_id' => $companyId,
        'code' => 'medical_gp',
        'name' => 'Medical GP',
        'default_unit' => ClaimType::UNIT_AMOUNT,
        'calculation_mode' => 'manual_amount',
        'receipt_requirement' => ClaimType::RECEIPT_NEVER,
        'provider_required' => false,
        'payroll_eligible' => true,
        'status' => 'active',
    ]);
    PayrollClaimTypePayItem::query()->create([
        'company_id' => $companyId,
        'claim_type_id' => $type->id,
        'payroll_pay_item_code' => 'claim_medical',
        'effective_from' => PAYROLL_WORKBENCH_MAPPING_EFFECTIVE_FROM,
        'effective_to' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(ClaimTypePayItemMapping::class)
        ->call('startEditing', $type->id)
        ->assertSet('editingClaimTypeId', $type->id)
        ->assertSet('editingEffectiveFrom', PAYROLL_WORKBENCH_MAPPING_EFFECTIVE_FROM)
        ->set('editingPayItemCode', 'claim_medical')
        ->call('cancelEditing')
        ->assertSet('editingClaimTypeId', 0)
        ->assertSet('editingPayItemCode', '')
        ->assertSet('editingEffectiveFrom', PAYROLL_WORKBENCH_CANCEL_EDITING_TODAY);
});

test('claim type mapping deleteMapping removes the dated row', function (): void {
    $admin = createAdminUser();
    $companyId = (int) $admin->company_id;
    payrollWorkbenchPayItem($companyId, 'claim_medical');
    $type = ClaimType::query()->create([
        'company_id' => $companyId,
        'code' => 'medical_gp',
        'name' => 'Medical GP',
        'default_unit' => ClaimType::UNIT_AMOUNT,
        'calculation_mode' => 'manual_amount',
        'receipt_requirement' => ClaimType::RECEIPT_NEVER,
        'provider_required' => false,
        'payroll_eligible' => true,
        'status' => 'active',
    ]);

    PayrollClaimTypePayItem::query()->create([
        'company_id' => $companyId,
        'claim_type_id' => $type->id,
        'payroll_pay_item_code' => 'claim_medical',
        'effective_from' => '2026-06-01',
        'effective_to' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(ClaimTypePayItemMapping::class)
        ->call('deleteMapping', $type->id, '2026-06-01')
        ->assertHasNoErrors();

    expect(PayrollClaimTypePayItem::query()->where('claim_type_id', $type->id)->count())->toBe(0);
});

test('leave type mapping cancelEditing clears edit state', function (): void {
    Carbon::setTestNow(PAYROLL_WORKBENCH_CANCEL_EDITING_TODAY);

    $admin = createAdminUser();
    $companyId = (int) $admin->company_id;
    payrollWorkbenchPayItem($companyId, 'unpaid_leave');
    $type = LeaveType::query()->create([
        'company_id' => $companyId,
        'code' => 'annual_leave',
        'name' => 'Annual Leave',
        'paid' => true,
        'default_unit' => LeaveType::UNIT_DAY,
        'default_approval_depth' => 1,
        'interacts_with_payroll' => true,
        'compulsory_attachment' => false,
        'status' => LeaveType::STATUS_ACTIVE,
    ]);
    PayrollLeaveTypePayItem::query()->create([
        'company_id' => $companyId,
        'leave_type_id' => $type->id,
        'payroll_pay_item_code' => 'unpaid_leave',
        'effective_from' => PAYROLL_WORKBENCH_MAPPING_EFFECTIVE_FROM,
        'effective_to' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(LeaveTypePayItemMapping::class)
        ->call('startEditing', $type->id)
        ->assertSet('editingLeaveTypeId', $type->id)
        ->assertSet('editingEffectiveFrom', PAYROLL_WORKBENCH_MAPPING_EFFECTIVE_FROM)
        ->set('editingPayItemCode', 'unpaid_leave')
        ->call('cancelEditing')
        ->assertSet('editingLeaveTypeId', 0)
        ->assertSet('editingPayItemCode', '')
        ->assertSet('editingEffectiveFrom', PAYROLL_WORKBENCH_CANCEL_EDITING_TODAY);
});

test('leave type mapping deleteMapping removes the dated row', function (): void {
    $admin = createAdminUser();
    $companyId = (int) $admin->company_id;
    payrollWorkbenchPayItem($companyId, 'unpaid_leave');
    $type = LeaveType::query()->create([
        'company_id' => $companyId,
        'code' => 'annual_leave',
        'name' => 'Annual Leave',
        'paid' => true,
        'default_unit' => LeaveType::UNIT_DAY,
        'default_approval_depth' => 1,
        'interacts_with_payroll' => true,
        'compulsory_attachment' => false,
        'status' => LeaveType::STATUS_ACTIVE,
    ]);

    PayrollLeaveTypePayItem::query()->create([
        'company_id' => $companyId,
        'leave_type_id' => $type->id,
        'payroll_pay_item_code' => 'unpaid_leave',
        'effective_from' => '2026-06-01',
        'effective_to' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(LeaveTypePayItemMapping::class)
        ->call('deleteMapping', $type->id, '2026-06-01')
        ->assertHasNoErrors();

    expect(PayrollLeaveTypePayItem::query()->where('leave_type_id', $type->id)->count())->toBe(0);
});
