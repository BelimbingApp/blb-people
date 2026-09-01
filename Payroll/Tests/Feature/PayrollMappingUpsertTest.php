<?php

use App\Core\Employee\Models\Employee;
use App\Domains\People\Attendance\Models\AttendanceAllowanceRule;
use App\Domains\People\Claim\Models\ClaimType;
use App\Domains\People\Leave\Models\LeaveType;
use App\Domains\People\Payroll\Livewire\AttendanceAllowanceMapping;
use App\Domains\People\Payroll\Livewire\ClaimTypePayItemMapping;
use App\Domains\People\Payroll\Livewire\Index as PayrollIndex;
use App\Domains\People\Payroll\Livewire\LeaveTypePayItemMapping;
use App\Domains\People\Payroll\Models\PayrollAttendanceRulePayItem;
use App\Domains\People\Payroll\Models\PayrollClaimTypePayItem;
use App\Domains\People\Payroll\Models\PayrollEmployeeStatutoryProfile;
use App\Domains\People\Payroll\Models\PayrollEmployerStatutoryProfile;
use App\Domains\People\Payroll\Models\PayrollLeaveTypePayItem;
use App\Domains\People\Payroll\Models\PayrollPayItem;
use App\Domains\People\Payroll\Models\PayrollStatutoryRuleSet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Re-saving a mapping whose upsert lookup key is a date column must be an
 * update, not a unique-index collision (#54): a bare 'Y-m-d' string key never
 * matches the date cast's stored value under SQLite, so updateOrCreate falls
 * through to a doomed insert. These drive the real Livewire save paths twice.
 */
function mappingUpsertPayItem(int $companyId, string $code): PayrollPayItem
{
    return PayrollPayItem::query()->create([
        'company_id' => $companyId,
        'code' => $code,
        'name' => strtoupper($code),
        'input_type' => 'earning',
        'status' => 'active',
    ]);
}

test('re-saving an attendance allowance mapping updates instead of colliding', function (): void {
    $admin = createAdminUser();
    $companyId = (int) $admin->company_id;
    mappingUpsertPayItem($companyId, 'meal_allowance');
    mappingUpsertPayItem($companyId, 'shift_allowance');

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

    foreach (['meal_allowance', 'shift_allowance'] as $code) {
        Livewire::actingAs($admin)
            ->test(AttendanceAllowanceMapping::class)
            ->call('startEditing', $rule->id)
            ->set('editingPayItemCode', $code)
            ->set('editingEffectiveFrom', '2026-06-01')
            ->call('saveMapping')
            ->assertHasNoErrors();
    }

    $mapping = PayrollAttendanceRulePayItem::query()
        ->where('attendance_allowance_rule_id', $rule->id)
        ->get();
    expect($mapping)->toHaveCount(1)
        ->and($mapping->first()->payroll_pay_item_code)->toBe('shift_allowance');
});

test('re-saving a claim type mapping updates instead of colliding', function (): void {
    $admin = createAdminUser();
    $companyId = (int) $admin->company_id;
    mappingUpsertPayItem($companyId, 'claim_medical');
    mappingUpsertPayItem($companyId, 'claim_travel');

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

    foreach (['claim_medical', 'claim_travel'] as $code) {
        Livewire::actingAs($admin)
            ->test(ClaimTypePayItemMapping::class)
            ->call('startEditing', $type->id)
            ->set('editingPayItemCode', $code)
            ->set('editingEffectiveFrom', '2026-06-01')
            ->call('saveMapping')
            ->assertHasNoErrors();
    }

    $mapping = PayrollClaimTypePayItem::query()->where('claim_type_id', $type->id)->get();
    expect($mapping)->toHaveCount(1)
        ->and($mapping->first()->payroll_pay_item_code)->toBe('claim_travel');
});

test('re-saving a leave type mapping updates instead of colliding', function (): void {
    $admin = createAdminUser();
    $companyId = (int) $admin->company_id;
    mappingUpsertPayItem($companyId, 'unpaid_leave');
    mappingUpsertPayItem($companyId, 'leave_encashment');

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

    // Raw seeder-style write first: the Livewire saves below must land on this
    // same row, proving raw and component paths agree on the key format.
    DB::table('people_payroll_leave_type_pay_items')->updateOrInsert(
        ['leave_type_id' => $type->id, 'effective_from' => Carbon::parse('2026-06-01')],
        [
            'company_id' => $companyId,
            'payroll_pay_item_code' => 'unpaid_leave',
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );

    foreach (['unpaid_leave', 'leave_encashment'] as $code) {
        Livewire::actingAs($admin)
            ->test(LeaveTypePayItemMapping::class)
            ->call('startEditing', $type->id)
            ->set('editingPayItemCode', $code)
            ->set('editingEffectiveFrom', '2026-06-01')
            ->call('saveMapping')
            ->assertHasNoErrors();
    }

    $mapping = PayrollLeaveTypePayItem::query()->where('leave_type_id', $type->id)->get();
    expect($mapping)->toHaveCount(1)
        ->and($mapping->first()->payroll_pay_item_code)->toBe('leave_encashment');
});

test('re-saving statutory profiles and rule sets updates instead of colliding', function (): void {
    $admin = createAdminUser();
    $companyId = (int) $admin->company_id;
    $employee = Employee::factory()->create(['company_id' => $companyId]);

    foreach (['pack-one', 'pack-two'] as $pack) {
        Livewire::actingAs($admin)
            ->test(PayrollIndex::class)
            ->set('employerProfileCountryIso', 'MY')
            ->set('employerProfileSourcePack', $pack)
            ->set('employerProfileSourceVersion', '1.0.0')
            ->set('employerProfileEffectiveFrom', '2026-06-01')
            ->set('employerProfileData', '{}')
            ->call('createEmployerProfile')
            ->assertHasNoErrors();

        Livewire::actingAs($admin)
            ->test(PayrollIndex::class)
            ->set('employeeProfileEmployeeId', (string) $employee->id)
            ->set('employeeProfileCountryIso', 'MY')
            ->set('employeeProfileSourcePack', $pack)
            ->set('employeeProfileSourceVersion', '1.0.0')
            ->set('employeeProfileEffectiveFrom', '2026-06-01')
            ->set('employeeProfileData', '{}')
            ->call('createEmployeeProfile')
            ->assertHasNoErrors();

        Livewire::actingAs($admin)
            ->test(PayrollIndex::class)
            ->set('ruleSetCountryIso', 'MY')
            ->set('ruleSetRuleKey', 'epf')
            ->set('ruleSetName', 'EPF '.$pack)
            ->set('ruleSetSourcePack', 'statutory-pack')
            ->set('ruleSetSourceVersion', '1.0.0')
            ->set('ruleSetEffectiveFrom', '2026-06-01')
            ->call('createRuleSet')
            ->assertHasNoErrors();
    }

    expect(PayrollEmployerStatutoryProfile::query()->where('company_id', $companyId)->get())
        ->toHaveCount(1)
        ->sequence(fn ($profile) => $profile->source_pack->toBe('pack-two'));
    expect(PayrollEmployeeStatutoryProfile::query()->where('employee_id', $employee->id)->get())
        ->toHaveCount(1)
        ->sequence(fn ($profile) => $profile->source_pack->toBe('pack-two'));
    $ruleSets = PayrollStatutoryRuleSet::query()->where('rule_key', 'epf')->get();
    expect($ruleSets)->toHaveCount(1)
        ->and($ruleSets->first()->name)->toBe('EPF pack-two');
});
