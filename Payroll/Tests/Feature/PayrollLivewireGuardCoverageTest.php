<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Core\Company\Models\Company;
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
use App\Domains\People\Payroll\Models\PayrollEmployeeStatutoryProfile;
use App\Domains\People\Payroll\Models\PayrollEmployerStatutoryProfile;
use App\Domains\People\Payroll\Models\PayrollInput;
use App\Domains\People\Payroll\Models\PayrollLeaveTypePayItem;
use App\Domains\People\Payroll\Models\PayrollPayItem;
use App\Domains\People\Payroll\Models\PayrollPayItemClassification;
use App\Domains\People\Payroll\Models\PayrollPeriod;
use App\Domains\People\Payroll\Models\PayrollRun;
use App\Domains\People\Payroll\Models\PayrollStatutoryRuleRow;
use App\Domains\People\Payroll\Models\PayrollStatutoryRuleSet;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Denials and refusals for every Payroll Livewire action
|--------------------------------------------------------------------------
|
| Every action already had an authorized happy path (#60, #61). None had a
| denial or a validation refusal. Each helper is local to this file so it
| runs alone; only createAdminUser() from tests/Pest.php is shared.
*/

const GUARD_COV_MAPPING_DATE = '2026-06-01';

/** @return array{0: User, 1: int} admin and company id */
function guardCovAdmin(): array
{
    $admin = createAdminUser();

    return [$admin, (int) $admin->company_id];
}

/** A same-company user holding only people.payroll.view. */
function guardCovViewer(int $companyId): User
{
    $role = Role::query()->firstOrCreate(
        ['company_id' => $companyId, 'code' => 'payroll_view_only_'.$companyId],
        ['name' => 'Payroll View Only', 'is_system' => false, 'grant_all' => false],
    );
    DB::table('base_authz_role_capabilities')->insertOrIgnore([
        'role_id' => $role->id,
        'capability_key' => 'people.payroll.view',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $viewer = User::factory()->create(['company_id' => $companyId]);
    PrincipalRole::query()->create([
        'company_id' => $companyId,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $viewer->id,
        'role_id' => $role->id,
    ]);

    return $viewer;
}

function guardCovRun(int $companyId, string $code, string $status = PayrollRun::STATUS_DRAFT): PayrollRun
{
    $calendar = PayrollCalendar::query()->create([
        'company_id' => $companyId,
        'code' => 'CAL-'.$code,
        'name' => 'Calendar '.$code,
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

    return PayrollRun::query()->create([
        'company_id' => $companyId,
        'payroll_calendar_id' => $calendar->id,
        'payroll_period_id' => $period->id,
        'code' => $code,
        'name' => 'Run '.$code,
        'status' => $status,
        'currency' => 'MYR',
    ]);
}

function guardCovPayItem(int $companyId, string $code): PayrollPayItem
{
    return PayrollPayItem::query()->create([
        'company_id' => $companyId,
        'code' => $code,
        'name' => strtoupper($code),
        'input_type' => PayrollInput::TYPE_EARNING,
        'status' => 'active',
    ]);
}

function guardCovAllowanceRule(int $companyId, string $code = 'MEAL_ALLOW'): AttendanceAllowanceRule
{
    return AttendanceAllowanceRule::query()->create([
        'company_id' => $companyId,
        'code' => $code,
        'name' => 'Meal allowance',
        'allowance_type' => AttendanceAllowanceRule::TYPE_DAILY,
        'resolution_method' => AttendanceAllowanceRule::RESOLUTION_SUM,
        'condition_rows' => [['description' => 'Always pay', 'amount' => 15, 'predicate' => []]],
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]);
}

function guardCovClaimType(int $companyId, string $code = 'medical_gp'): ClaimType
{
    return ClaimType::query()->create([
        'company_id' => $companyId,
        'code' => $code,
        'name' => 'Medical GP',
        'default_unit' => ClaimType::UNIT_AMOUNT,
        'calculation_mode' => 'manual_amount',
        'receipt_requirement' => ClaimType::RECEIPT_NEVER,
        'provider_required' => false,
        'payroll_eligible' => true,
        'status' => 'active',
    ]);
}

function guardCovLeaveType(int $companyId, string $code = 'annual_leave'): LeaveType
{
    return LeaveType::query()->create([
        'company_id' => $companyId,
        'code' => $code,
        'name' => 'Annual Leave',
        'paid' => true,
        'default_unit' => LeaveType::UNIT_DAY,
        'default_approval_depth' => 1,
        'interacts_with_payroll' => true,
        'compulsory_attachment' => false,
        'status' => LeaveType::STATUS_ACTIVE,
    ]);
}

function guardCovErrorToast(): Closure
{
    return fn (string $event, array $params): bool => ($params['variant'] ?? null) === 'error';
}

// ─── Index: navigation and runs ───────────────────────────────────────────────

test('setTab ignores an unknown tab and selectRun cannot surface a run from another company', function (): void {
    [$admin, $companyId] = guardCovAdmin();
    $own = guardCovRun($companyId, 'OWN');
    $foreign = guardCovRun((int) Company::factory()->create()->id, 'FOREIGN');

    Livewire::actingAs($admin)->test(PayrollIndex::class)
        ->call('setTab', 'not-a-tab')
        ->assertSet('tab', 'runs')
        ->call('setTab', 'rules')
        ->assertSet('tab', 'rules')
        ->call('selectRun', $foreign->id)
        ->assertSet('selectedRunId', $foreign->id)
        ->assertSet('tab', 'runs')
        ->assertViewHas('selectedRun', null)
        ->call('selectRun', $own->id)
        ->assertViewHas('selectedRun', fn ($run): bool => $run !== null && (int) $run->id === (int) $own->id);
});

test('calculateRun denies viewers, refuses a foreign run, and reports a closed run as an error', function (): void {
    [$admin, $companyId] = guardCovAdmin();
    $closed = guardCovRun($companyId, 'CLOSED', PayrollRun::STATUS_CLOSED);
    $foreign = guardCovRun((int) Company::factory()->create()->id, 'FOREIGN');

    expect(fn () => Livewire::actingAs(guardCovViewer($companyId))->test(PayrollIndex::class)->call('calculateRun', $closed->id))
        ->toThrow(AuthorizationDeniedException::class);

    expect(fn () => Livewire::actingAs($admin)->test(PayrollIndex::class)->call('calculateRun', $foreign->id))
        ->toThrow(ModelNotFoundException::class);

    Livewire::actingAs($admin)->test(PayrollIndex::class)
        ->call('calculateRun', $closed->id)
        ->assertDispatched('notify', guardCovErrorToast());

    expect($closed->refresh()->status)->toBe(PayrollRun::STATUS_CLOSED)
        ->and($foreign->refresh()->status)->toBe(PayrollRun::STATUS_DRAFT);
});

test('transitionPayrollRun ignores an unknown transition, denies viewers, refuses a foreign run, and reports a closed run', function (): void {
    [$admin, $companyId] = guardCovAdmin();
    $draft = guardCovRun($companyId, 'DRAFT');
    $closed = guardCovRun($companyId, 'CLOSED', PayrollRun::STATUS_CLOSED);
    $foreign = guardCovRun((int) Company::factory()->create()->id, 'FOREIGN');

    // An unknown transition returns before authorization, so even a viewer sees nothing.
    Livewire::actingAs(guardCovViewer($companyId))->test(PayrollIndex::class)
        ->call('transitionPayrollRun', 'launch', $draft->id)
        ->assertNotDispatched('notify');

    expect(fn () => Livewire::actingAs(guardCovViewer($companyId))->test(PayrollIndex::class)->call('transitionPayrollRun', 'void', $draft->id))
        ->toThrow(AuthorizationDeniedException::class);

    expect(fn () => Livewire::actingAs($admin)->test(PayrollIndex::class)->call('transitionPayrollRun', 'void', $foreign->id))
        ->toThrow(ModelNotFoundException::class);

    Livewire::actingAs($admin)->test(PayrollIndex::class)
        ->call('transitionPayrollRun', 'review', $closed->id)
        ->assertDispatched('notify', guardCovErrorToast());

    expect($draft->refresh()->status)->toBe(PayrollRun::STATUS_DRAFT)
        ->and($closed->refresh()->status)->toBe(PayrollRun::STATUS_CLOSED)
        ->and($foreign->refresh()->status)->toBe(PayrollRun::STATUS_DRAFT);
});

// ─── Index: pay items and classifications ─────────────────────────────────────

test('createPayItem denies viewers and refuses blank, symbol-only, and duplicate codes', function (): void {
    [$admin, $companyId] = guardCovAdmin();
    guardCovPayItem($companyId, 'meal_allowance');

    expect(fn () => Livewire::actingAs(guardCovViewer($companyId))->test(PayrollIndex::class)
        ->set('payItemCode', 'viewer_item')->set('payItemName', 'Viewer item')->call('createPayItem'))
        ->toThrow(AuthorizationDeniedException::class);

    Livewire::actingAs($admin)->test(PayrollIndex::class)
        ->set('payItemCode', '')
        ->set('payItemName', '')
        ->call('createPayItem')
        ->assertHasErrors(['payItemCode', 'payItemName']);

    Livewire::actingAs($admin)->test(PayrollIndex::class)
        ->set('payItemCode', '!!!')
        ->set('payItemName', 'Symbols')
        ->call('createPayItem')
        ->assertHasErrors(['payItemCode' => 'Enter at least one letter or number.']);

    Livewire::actingAs($admin)->test(PayrollIndex::class)
        ->set('payItemCode', 'Meal Allowance')
        ->set('payItemName', 'Meal allowance again')
        ->call('createPayItem')
        ->assertHasErrors(['payItemCode' => 'This pay item code is already used.']);

    expect(PayrollPayItem::query()->where('company_id', $companyId)->count())->toBe(1);
});

test('createClassification denies viewers and refuses a pay item from another company', function (): void {
    [$admin, $companyId] = guardCovAdmin();
    $foreignItem = guardCovPayItem((int) Company::factory()->create()->id, 'foreign_item');
    $fields = [
        'classificationPayItemId' => (string) $foreignItem->id,
        'classificationKey' => 'epf_category',
        'classificationValue' => 'standard',
        'classificationEffectiveFrom' => '2026-01-01',
        'classificationSourcePack' => 'my-standard',
        'classificationSourceVersion' => '1',
    ];

    expect(fn () => Livewire::actingAs(guardCovViewer($companyId))->test(PayrollIndex::class)->call('createClassification'))
        ->toThrow(AuthorizationDeniedException::class);

    $component = Livewire::actingAs($admin)->test(PayrollIndex::class);
    foreach ($fields as $name => $value) {
        $component->set($name, $value);
    }
    $component->call('createClassification')->assertHasErrors(['classificationPayItemId']);

    expect(PayrollPayItemClassification::query()->where('payroll_pay_item_id', $foreignItem->id)->exists())->toBeFalse();
});

// ─── Index: statutory profiles and rule tables ────────────────────────────────

test('createEmployerProfile denies viewers and refuses a profile that is not a JSON object', function (): void {
    [$admin, $companyId] = guardCovAdmin();

    expect(fn () => Livewire::actingAs(guardCovViewer($companyId))->test(PayrollIndex::class)->call('createEmployerProfile'))
        ->toThrow(AuthorizationDeniedException::class);

    Livewire::actingAs($admin)->test(PayrollIndex::class)
        ->set('employerProfileCountryIso', 'MY')
        ->set('employerProfileSourcePack', 'my-standard')
        ->set('employerProfileSourceVersion', '1')
        ->set('employerProfileEffectiveFrom', '2026-01-01')
        ->set('employerProfileData', 'not json')
        ->call('createEmployerProfile')
        ->assertHasErrors(['employerProfileData' => 'Enter a valid JSON object.']);

    expect(PayrollEmployerStatutoryProfile::query()->where('company_id', $companyId)->exists())->toBeFalse();
});

test('createEmployeeProfile denies viewers and refuses an employee from another company', function (): void {
    [$admin, $companyId] = guardCovAdmin();
    $foreignEmployee = Employee::factory()->create(['company_id' => Company::factory()->create()->id]);

    expect(fn () => Livewire::actingAs(guardCovViewer($companyId))->test(PayrollIndex::class)->call('createEmployeeProfile'))
        ->toThrow(AuthorizationDeniedException::class);

    Livewire::actingAs($admin)->test(PayrollIndex::class)
        ->set('employeeProfileEmployeeId', (string) $foreignEmployee->id)
        ->set('employeeProfileCountryIso', 'MY')
        ->set('employeeProfileSourcePack', 'my-standard')
        ->set('employeeProfileSourceVersion', '1')
        ->set('employeeProfileEffectiveFrom', '2026-01-01')
        ->set('employeeProfileData', '{"epf_category":"standard"}')
        ->call('createEmployeeProfile')
        ->assertHasErrors(['employeeProfileEmployeeId']);

    expect(PayrollEmployeeStatutoryProfile::query()->where('employee_id', $foreignEmployee->id)->exists())->toBeFalse();
});

test('createRuleSet denies viewers and refuses a bad country code and a rounding policy that is not JSON', function (): void {
    [$admin, $companyId] = guardCovAdmin();

    expect(fn () => Livewire::actingAs(guardCovViewer($companyId))->test(PayrollIndex::class)->call('createRuleSet'))
        ->toThrow(AuthorizationDeniedException::class);

    $component = Livewire::actingAs($admin)->test(PayrollIndex::class)
        ->set('ruleSetCountryIso', 'MYS')
        ->set('ruleSetRuleKey', 'epf')
        ->set('ruleSetName', 'EPF')
        ->set('ruleSetSourcePack', 'my-standard')
        ->set('ruleSetSourceVersion', '1')
        ->set('ruleSetEffectiveFrom', '2026-01-01')
        ->call('createRuleSet')
        ->assertHasErrors(['ruleSetCountryIso']);

    $component
        ->set('ruleSetCountryIso', 'MY')
        ->set('ruleSetRoundingPolicy', '{not json')
        ->call('createRuleSet')
        ->assertHasErrors(['ruleSetRoundingPolicy' => 'Enter a valid JSON object.']);

    expect(PayrollStatutoryRuleSet::query()->where('rule_key', 'epf')->exists())->toBeFalse();
});

test('createRuleRow denies viewers and refuses an unknown rule set and a non-numeric rate', function (): void {
    [$admin, $companyId] = guardCovAdmin();
    $ruleSet = PayrollStatutoryRuleSet::query()->create([
        'country_iso' => 'MY',
        'rule_key' => 'epf',
        'name' => 'EPF',
        'source_pack' => 'my-standard',
        'source_version' => '1',
        'effective_from' => '2026-01-01',
    ]);

    expect(fn () => Livewire::actingAs(guardCovViewer($companyId))->test(PayrollIndex::class)->call('createRuleRow'))
        ->toThrow(AuthorizationDeniedException::class);

    Livewire::actingAs($admin)->test(PayrollIndex::class)
        ->set('ruleRowRuleSetId', '999999')
        ->call('createRuleRow')
        ->assertHasErrors(['ruleRowRuleSetId']);

    Livewire::actingAs($admin)->test(PayrollIndex::class)
        ->set('ruleRowRuleSetId', (string) $ruleSet->id)
        ->set('ruleRowEmployeeRate', 'eleven percent')
        ->call('createRuleRow')
        ->assertHasErrors(['ruleRowEmployeeRate']);

    expect(PayrollStatutoryRuleRow::query()->where('payroll_statutory_rule_set_id', $ruleSet->id)->exists())->toBeFalse();
});

// ─── Mapping screens ──────────────────────────────────────────────────────────

test('attendance allowance mapping denies viewers and refuses a foreign rule and an unknown or foreign pay item', function (): void {
    [$admin, $companyId] = guardCovAdmin();
    $rule = guardCovAllowanceRule($companyId);
    $otherCompany = (int) Company::factory()->create()->id;
    $foreignRule = guardCovAllowanceRule($otherCompany, 'FOREIGN');
    guardCovPayItem($otherCompany, 'foreign_allowance');

    Livewire::actingAs(guardCovViewer($companyId))->test(AttendanceAllowanceMapping::class)
        ->call('startEditing', $rule->id)
        ->assertForbidden();

    Livewire::actingAs(guardCovViewer($companyId))->test(AttendanceAllowanceMapping::class)
        ->set('editingRuleId', $rule->id)
        ->set('editingPayItemCode', 'meal_allowance')
        ->call('saveMapping')
        ->assertForbidden();

    expect(fn () => Livewire::actingAs($admin)->test(AttendanceAllowanceMapping::class)->call('startEditing', $foreignRule->id))
        ->toThrow(ModelNotFoundException::class);

    Livewire::actingAs($admin)->test(AttendanceAllowanceMapping::class)
        ->call('startEditing', $rule->id)
        ->set('editingPayItemCode', 'no_such_item')
        ->call('saveMapping')
        ->assertHasErrors(['editingPayItemCode'])
        ->set('editingPayItemCode', 'foreign_allowance')
        ->call('saveMapping')
        ->assertHasErrors(['editingPayItemCode'])
        ->set('editingEffectiveFrom', '')
        ->call('saveMapping')
        ->assertHasErrors(['editingEffectiveFrom']);

    expect(PayrollAttendanceRulePayItem::query()->count())->toBe(0);
});

test('attendance allowance mapping deleteMapping denies viewers and cannot delete another company mapping', function (): void {
    [$admin, $companyId] = guardCovAdmin();
    $rule = guardCovAllowanceRule($companyId);
    guardCovPayItem($companyId, 'meal_allowance');
    $own = PayrollAttendanceRulePayItem::query()->create([
        'company_id' => $companyId,
        'attendance_allowance_rule_id' => $rule->id,
        'payroll_pay_item_code' => 'meal_allowance',
        'effective_from' => GUARD_COV_MAPPING_DATE,
        'effective_to' => null,
    ]);
    $otherCompany = (int) Company::factory()->create()->id;
    $foreignRule = guardCovAllowanceRule($otherCompany, 'FOREIGN');
    guardCovPayItem($otherCompany, 'foreign_allowance');
    $foreign = PayrollAttendanceRulePayItem::query()->create([
        'company_id' => $otherCompany,
        'attendance_allowance_rule_id' => $foreignRule->id,
        'payroll_pay_item_code' => 'foreign_allowance',
        'effective_from' => GUARD_COV_MAPPING_DATE,
        'effective_to' => null,
    ]);

    Livewire::actingAs(guardCovViewer($companyId))->test(AttendanceAllowanceMapping::class)
        ->call('deleteMapping', $rule->id, GUARD_COV_MAPPING_DATE)
        ->assertForbidden();

    Livewire::actingAs($admin)->test(AttendanceAllowanceMapping::class)
        ->call('deleteMapping', $foreignRule->id, GUARD_COV_MAPPING_DATE);

    expect(PayrollAttendanceRulePayItem::query()->whereKey($own->id)->exists())->toBeTrue()
        ->and(PayrollAttendanceRulePayItem::query()->whereKey($foreign->id)->exists())->toBeTrue();
});

test('claim type mapping denies viewers and refuses a foreign claim type and an unknown pay item', function (): void {
    [$admin, $companyId] = guardCovAdmin();
    $type = guardCovClaimType($companyId);
    $foreignType = guardCovClaimType((int) Company::factory()->create()->id, 'foreign_claim');

    Livewire::actingAs(guardCovViewer($companyId))->test(ClaimTypePayItemMapping::class)
        ->call('startEditing', $type->id)
        ->assertForbidden();

    Livewire::actingAs(guardCovViewer($companyId))->test(ClaimTypePayItemMapping::class)
        ->set('editingClaimTypeId', $type->id)
        ->set('editingPayItemCode', 'claim_medical')
        ->call('saveMapping')
        ->assertForbidden();

    expect(fn () => Livewire::actingAs($admin)->test(ClaimTypePayItemMapping::class)->call('startEditing', $foreignType->id))
        ->toThrow(ModelNotFoundException::class);

    Livewire::actingAs($admin)->test(ClaimTypePayItemMapping::class)
        ->call('startEditing', $type->id)
        ->set('editingPayItemCode', 'no_such_item')
        ->call('saveMapping')
        ->assertHasErrors(['editingPayItemCode']);

    expect(PayrollClaimTypePayItem::query()->count())->toBe(0);
});

test('claim type mapping deleteMapping denies viewers and cannot delete another company mapping', function (): void {
    [$admin, $companyId] = guardCovAdmin();
    $type = guardCovClaimType($companyId);
    guardCovPayItem($companyId, 'claim_medical');
    $own = PayrollClaimTypePayItem::query()->create([
        'company_id' => $companyId,
        'claim_type_id' => $type->id,
        'payroll_pay_item_code' => 'claim_medical',
        'effective_from' => GUARD_COV_MAPPING_DATE,
        'effective_to' => null,
    ]);
    $otherCompany = (int) Company::factory()->create()->id;
    $foreignType = guardCovClaimType($otherCompany, 'foreign_claim');
    guardCovPayItem($otherCompany, 'foreign_claim_item');
    $foreign = PayrollClaimTypePayItem::query()->create([
        'company_id' => $otherCompany,
        'claim_type_id' => $foreignType->id,
        'payroll_pay_item_code' => 'foreign_claim_item',
        'effective_from' => GUARD_COV_MAPPING_DATE,
        'effective_to' => null,
    ]);

    Livewire::actingAs(guardCovViewer($companyId))->test(ClaimTypePayItemMapping::class)
        ->call('deleteMapping', $type->id, GUARD_COV_MAPPING_DATE)
        ->assertForbidden();

    Livewire::actingAs($admin)->test(ClaimTypePayItemMapping::class)
        ->call('deleteMapping', $foreignType->id, GUARD_COV_MAPPING_DATE);

    expect(PayrollClaimTypePayItem::query()->whereKey($own->id)->exists())->toBeTrue()
        ->and(PayrollClaimTypePayItem::query()->whereKey($foreign->id)->exists())->toBeTrue();
});

test('leave type mapping denies viewers and refuses a foreign leave type and an unknown pay item', function (): void {
    [$admin, $companyId] = guardCovAdmin();
    $type = guardCovLeaveType($companyId);
    $foreignType = guardCovLeaveType((int) Company::factory()->create()->id, 'foreign_leave');

    Livewire::actingAs(guardCovViewer($companyId))->test(LeaveTypePayItemMapping::class)
        ->call('startEditing', $type->id)
        ->assertForbidden();

    Livewire::actingAs(guardCovViewer($companyId))->test(LeaveTypePayItemMapping::class)
        ->set('editingLeaveTypeId', $type->id)
        ->set('editingPayItemCode', 'unpaid_leave')
        ->call('saveMapping')
        ->assertForbidden();

    expect(fn () => Livewire::actingAs($admin)->test(LeaveTypePayItemMapping::class)->call('startEditing', $foreignType->id))
        ->toThrow(ModelNotFoundException::class);

    Livewire::actingAs($admin)->test(LeaveTypePayItemMapping::class)
        ->call('startEditing', $type->id)
        ->set('editingPayItemCode', 'no_such_item')
        ->call('saveMapping')
        ->assertHasErrors(['editingPayItemCode']);

    expect(PayrollLeaveTypePayItem::query()->count())->toBe(0);
});

test('leave type mapping deleteMapping denies viewers and cannot delete another company mapping', function (): void {
    [$admin, $companyId] = guardCovAdmin();
    $type = guardCovLeaveType($companyId);
    guardCovPayItem($companyId, 'unpaid_leave');
    $own = PayrollLeaveTypePayItem::query()->create([
        'company_id' => $companyId,
        'leave_type_id' => $type->id,
        'payroll_pay_item_code' => 'unpaid_leave',
        'effective_from' => GUARD_COV_MAPPING_DATE,
        'effective_to' => null,
    ]);
    $otherCompany = (int) Company::factory()->create()->id;
    $foreignType = guardCovLeaveType($otherCompany, 'foreign_leave');
    guardCovPayItem($otherCompany, 'foreign_leave_item');
    $foreign = PayrollLeaveTypePayItem::query()->create([
        'company_id' => $otherCompany,
        'leave_type_id' => $foreignType->id,
        'payroll_pay_item_code' => 'foreign_leave_item',
        'effective_from' => GUARD_COV_MAPPING_DATE,
        'effective_to' => null,
    ]);

    Livewire::actingAs(guardCovViewer($companyId))->test(LeaveTypePayItemMapping::class)
        ->call('deleteMapping', $type->id, GUARD_COV_MAPPING_DATE)
        ->assertForbidden();

    Livewire::actingAs($admin)->test(LeaveTypePayItemMapping::class)
        ->call('deleteMapping', $foreignType->id, GUARD_COV_MAPPING_DATE);

    expect(PayrollLeaveTypePayItem::query()->whereKey($own->id)->exists())->toBeTrue()
        ->and(PayrollLeaveTypePayItem::query()->whereKey($foreign->id)->exists())->toBeTrue();
});
