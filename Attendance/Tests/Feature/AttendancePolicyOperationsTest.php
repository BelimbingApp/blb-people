<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\Department;
use App\Modules\Core\Company\Models\DepartmentType;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use App\Modules\People\Attendance\Livewire\AllowanceRules;
use App\Modules\People\Attendance\Livewire\Approvals;
use App\Modules\People\Attendance\Livewire\PolicyGroups;
use App\Modules\People\Attendance\Livewire\PolicyGroupValidator;
use App\Modules\People\Attendance\Livewire\Rosters;
use App\Modules\People\Attendance\Livewire\ShiftTemplates;
use App\Modules\People\Attendance\Models\AttendanceAllowanceRule;
use App\Modules\People\Attendance\Models\AttendanceDay;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendancePunchWindow;
use App\Modules\People\Attendance\Models\AttendanceRosterAcknowledgment;
use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use App\Modules\People\Attendance\Models\AttendanceRosterLock;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use App\Modules\People\Attendance\Services\AttendanceDayResolverService;
use App\Modules\People\Attendance\Services\AttendancePolicySimulationService;
use App\Modules\People\Attendance\Services\AttendancePolicyValidationService;
use App\Modules\People\Settings\Models\EmployeeWorkProfile;
use App\Modules\People\Settings\Models\PeopleReferenceEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const ATTENDANCE_POLICY_OFFICE_EMPLOYEE_NAME = 'Office Olive';
const ATTENDANCE_POLICY_PRODUCTION_EMPLOYEE_NAME = 'Production Pat';
const ATTENDANCE_SHIFT_CODE_LABEL = 'Shift code';

function attendancePolicyGroupForOperationsTest(Company $company, array $attributes = []): AttendancePolicyGroup
{
    return AttendancePolicyGroup::query()->create(array_replace([
        'company_id' => $company->id,
        'code' => 'STD',
        'name' => 'Standard',
        'effective_from' => '2026-01-01',
    ], $attributes));
}

function attendanceShiftTemplateForOperationsTest(Company $company, array $attributes = []): AttendanceShiftTemplate
{
    return AttendanceShiftTemplate::query()->create(array_replace([
        'company_id' => $company->id,
        'code' => 'DAY',
        'name' => 'Day Shift',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
    ], $attributes));
}

function attendanceRosterAssignmentForOperationsTest(
    Company $company,
    Employee $employee,
    AttendanceShiftTemplate $shift,
    AttendancePolicyGroup $policyGroup,
    array $attributes = [],
): AttendanceRosterAssignment {
    return AttendanceRosterAssignment::query()->create(array_replace([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_shift_template_id' => $shift->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'effective_from' => '2026-09-01',
        'effective_to' => '2026-09-01',
        'publish_state' => 'draft',
        'lock_state' => 'open',
        'revision' => 1,
        'exceptions' => [],
        'metadata' => [],
    ], $attributes));
}

function startAttendanceTemplateBuilderForOperationsTest(
    string $component,
    string $startMethod,
    string $useMethod,
    string $templateKey,
    string $templateListText,
    string $formText,
): Testable {
    return Livewire::test($component)
        ->assertSet('mode', 'list')
        ->assertDontSee($templateListText)
        ->call($startMethod)
        ->assertSet('mode', 'form')
        ->assertSee($templateListText)
        ->assertDontSee($formText)
        ->call($useMethod, $templateKey)
        ->assertSee($formText);
}

/**
 * @return array{Employee, AttendancePolicyGroup, AttendanceShiftTemplate, string, string}
 */
function publishedRosterScenarioForOperationsTest(Company $company): array
{
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $policyGroup = attendancePolicyGroupForOperationsTest($company);
    $shift = attendanceShiftTemplateForOperationsTest($company);
    $monday = CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    $sunday = CarbonImmutable::today()->endOfWeek(CarbonImmutable::SUNDAY)->toDateString();

    attendanceRosterAssignmentForOperationsTest($company, $employee, $shift, $policyGroup, [
        'effective_from' => $monday,
        'effective_to' => $sunday,
        'publish_state' => 'published',
    ]);

    return [$employee, $policyGroup, $shift, $monday, $sunday];
}

it('returns stable validation findings for unsafe attendance policy setup', function (): void {
    $company = Company::factory()->minimal()->create();
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'BROKEN',
        'name' => 'Broken policy',
        'effective_from' => '2026-01-01',
        'work_hour_rules' => ['daily_rounding' => ['method' => 'sideways', 'minutes' => 15]],
        'lateness_rules' => ['grace' => ['in' => -5]],
        'overtime_export_rules' => ['normal' => [['lte_hours' => 2]]],
    ]);
    AttendanceAllowanceRule::query()->create([
        'company_id' => $company->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'code' => 'MEAL',
        'name' => 'Meal allowance',
        'allowance_type' => AttendanceAllowanceRule::TYPE_DAILY,
        'resolution_method' => AttendanceAllowanceRule::RESOLUTION_SUM,
        'condition_rows' => [['description' => 'Missing amount and predicate']],
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]);

    $result = app(AttendancePolicyValidationService::class)->validate($policyGroup);

    expect($result['status'])->toBe('error')
        ->and(collect($result['findings'])->pluck('code')->all())->toContain(
            'rounding_method_invalid',
            'lateness_grace_invalid',
            'overtime_export_pay_item_missing',
            'allowance_condition_amount_invalid',
            'allowance_condition_predicate_missing',
        );
});

it('emits validation findings as JSON from the attendance policy validate command', function (): void {
    $company = Company::factory()->minimal()->create();
    AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'STD',
        'name' => 'Standard',
        'effective_from' => '2026-01-01',
        'lateness_rules' => ['daily_rounding' => ['method' => 'ceiling', 'minutes' => 15]],
    ]);

    $exitCode = Artisan::call('blb:attendance:policy:validate', [
        'policy' => 'STD',
        '--company' => $company->id,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($payload['status'])->toBe('ok')
        ->and($payload['policy_group']['code'])->toBe('STD')
        ->and($payload['findings'])->toBe([]);
});

it('simulates policy outcomes and allowance candidates without creating attendance facts', function (): void {
    $company = Company::factory()->minimal()->create();
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'NIGHT',
        'name' => 'Night policy',
        'effective_from' => '2026-01-01',
        'lateness_rules' => ['grace' => ['in' => 10]],
    ]);
    AttendanceAllowanceRule::query()->create([
        'company_id' => $company->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'code' => 'NIGHT_ALLOWANCE',
        'name' => 'Night allowance',
        'allowance_type' => AttendanceAllowanceRule::TYPE_DAILY,
        'resolution_method' => AttendanceAllowanceRule::RESOLUTION_SUM,
        'condition_rows' => [
            ['description' => 'Clock out after 20:00', 'amount' => 1, 'predicate' => ['clock_out_after' => '20:00', 'min_worked_minutes' => 240]],
        ],
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]);
    $shift = AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'DAY',
        'name' => 'Day Shift',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
    ]);

    $result = app(AttendancePolicySimulationService::class)->simulate($policyGroup, $shift, '2026-05-14', '08:12', '20:30');

    expect($result['status'])->toBe('warning')
        ->and($result['metrics']['late_minutes'])->toBe(2)
        ->and($result['metrics']['worked_minutes'])->toBe(738)
        ->and($result['metrics']['overtime_candidate_minutes'])->toBe(258)
        ->and($result['allowance_candidates'][0]['code'])->toBe('NIGHT_ALLOWANCE');
});

it('only surfaces shift-scoped allowance rules when the matching shift is simulated', function (): void {
    $company = Company::factory()->minimal()->create();
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'ROT',
        'name' => 'Rotation policy',
        'effective_from' => '2026-01-01',
        'lateness_rules' => ['grace' => ['in' => 0]],
    ]);
    $dayShift = AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'DAY',
        'name' => 'Day shift',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
    ]);
    $nightShift = AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'NIGHT',
        'name' => 'Night shift',
        'starts_at' => '20:00:00',
        'ends_at' => '05:00:00',
        'crosses_midnight' => true,
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
    ]);
    AttendanceAllowanceRule::query()->create([
        'company_id' => $company->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'attendance_shift_template_id' => $nightShift->id,
        'code' => 'NIGHT_DIFFERENTIAL',
        'name' => 'Night differential',
        'allowance_type' => AttendanceAllowanceRule::TYPE_DAILY,
        'resolution_method' => AttendanceAllowanceRule::RESOLUTION_SUM,
        'condition_rows' => [
            ['description' => 'Always', 'amount' => 5, 'predicate' => []],
        ],
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]);

    $simulator = app(AttendancePolicySimulationService::class);
    $dayResult = $simulator->simulate($policyGroup, $dayShift, '2026-05-14', '08:00', '17:00');
    $nightResult = $simulator->simulate($policyGroup, $nightShift, '2026-05-14', '20:00', '05:00');

    expect($dayResult['allowance_candidates'])->toBe([])
        ->and($nightResult['allowance_candidates'][0]['code'])->toBe('NIGHT_DIFFERENTIAL');
});

it('emits simulation results as JSON from the attendance policy simulate command', function (): void {
    $company = Company::factory()->minimal()->create();
    AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'STD',
        'name' => 'Standard',
        'effective_from' => '2026-01-01',
        'lateness_rules' => ['grace' => ['in' => 0]],
    ]);
    AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'DAY',
        'name' => 'Day Shift',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
    ]);

    $exitCode = Artisan::call('blb:attendance:policy:simulate', [
        'policy' => 'STD',
        '--company' => $company->id,
        '--shift' => 'DAY',
        '--date' => '2026-05-14',
        '--clock-in' => '08:12',
        '--clock-out' => '17:30',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($payload['policy_group']['code'])->toBe('STD')
        ->and($payload['shift_template']['code'])->toBe('DAY')
        ->and($payload['metrics']['late_minutes'])->toBe(12)
        ->and($payload['metrics']['overtime_candidate_minutes'])->toBe(78);
});

it('routes managers from the policy groups list into the validator and runs validation+simulation', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'STD',
        'name' => 'Standard',
        'effective_from' => '2026-01-01',
        'lateness_rules' => ['grace' => ['in' => 0]],
    ]);
    $shift = AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'DAY',
        'name' => 'Day Shift',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
    ]);

    $this->actingAs($user);

    Livewire::test(PolicyGroups::class)
        ->assertSee('Policy Groups')
        ->assertDontSee('Validation findings')
        ->call('simulatePolicyGroup', $policyGroup->id)
        ->assertRedirect(route('people.attendance.policy-groups.validator', ['policyGroup' => $policyGroup->id]));

    Livewire::test(PolicyGroupValidator::class, ['policyGroup' => $policyGroup->id])
        ->assertSee('Validation findings')
        ->assertSet('policyPreviewPolicyId', (string) $policyGroup->id)
        ->call('validatePolicyPreview')
        ->assertSee('No validation findings for this policy group.')
        ->set('policyPreviewShiftId', (string) $shift->id)
        ->set('policyPreviewDate', '2026-05-14')
        ->set('policyPreviewClockIn', '08:12')
        ->set('policyPreviewClockOut', '17:30')
        ->call('simulatePolicyPreview')
        ->assertSee('OT candidate')
        ->assertSee('Worked time exceeds expected work minutes by 78 minute(s); this is only an overtime candidate until approved.')
        ->assertHasNoErrors();
});

it('lets managers build, save, and edit policies inline on the studio page', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);

    $this->actingAs($user);

    startAttendanceTemplateBuilderForOperationsTest(
        PolicyGroups::class,
        'startNewPolicy',
        'usePolicyTemplate',
        'office-grace',
        'Templates',
        'Identification',
    )
        ->assertSee('Policy Groups')
        ->assertSet('policyGraceIn', '10')
        ->set('policyCode', 'std_8_5')
        ->set('policyName', 'Standard 8 to 5')
        ->set('policyEffectiveFrom', '2026-01-01')
        ->set('policyGraceIn', '5')
        ->set('policyNormalOvertimePayItem', 'overtime')
        ->set('policyLatenessPayItem', 'lateness_deduction')
        ->call('savePolicyGroup')
        ->assertHasNoErrors()
        ->assertSet('mode', 'list')
        ->assertSee('Policy group saved.')
        ->assertSee('STD_8_5');

    $policy = AttendancePolicyGroup::query()
        ->where('company_id', $company->id)
        ->where('code', 'STD_8_5')
        ->firstOrFail();

    expect($policy->lateness_rules['grace']['in'])->toBe(5)
        ->and($policy->work_hour_rules['daily_rounding'])->toBe(['method' => 'nearest', 'minutes' => 15])
        ->and($policy->overtime_export_rules['normal'][0]['pay_item_code'])->toBe('overtime')
        ->and($policy->currency)->toBe('MYR');

    Livewire::test(PolicyGroups::class)
        ->call('editPolicyGroup', $policy->id)
        ->assertSet('mode', 'form')
        ->assertSet('editingPolicyGroupId', $policy->id)
        ->assertSet('policyGraceIn', '5')
        ->set('policyGraceIn', '10')
        ->call('savePolicyGroup')
        ->assertHasNoErrors()
        ->assertSet('mode', 'list');

    expect($policy->refresh()->version)->toBe(2)
        ->and($policy->lateness_rules['grace']['in'])->toBe(10);
});

it('restores policy edit mode from the URL', function (): void {
    $user = createAdminUser();
    $policy = AttendancePolicyGroup::query()->create([
        'company_id' => $user->company_id,
        'code' => 'URL_POLICY',
        'name' => 'URL policy',
        'effective_from' => '2026-01-01',
        'lateness_rules' => ['grace' => ['in' => 7]],
    ]);

    Livewire::actingAs($user)
        ->withQueryParams(['policy' => $policy->id])
        ->test(PolicyGroups::class)
        ->assertSet('mode', 'form')
        ->assertSet('editingPolicyGroupId', $policy->id)
        ->assertSet('policyCode', 'URL_POLICY')
        ->assertSet('policyGraceIn', '7')
        ->assertSee('Identification');
});

it('restores policy create mode and selected template from the URL', function (): void {
    $user = createAdminUser();

    Livewire::actingAs($user)
        ->withQueryParams(['mode' => 'form', 'template' => 'office-grace'])
        ->test(PolicyGroups::class)
        ->assertSet('mode', 'form')
        ->assertSet('editingPolicyGroupId', null)
        ->assertSet('selectedPolicyTemplateKey', 'office-grace')
        ->assertSet('showPolicyBuilderForm', true)
        ->assertSet('policyCode', 'OFFICE_GRACE')
        ->assertSet('policyGraceIn', '10')
        ->assertSee('Identification');
});

it('lists field errors prominently when saving a policy with invalid input', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    Livewire::test(PolicyGroups::class)
        ->call('startNewPolicy')
        ->call('usePolicyTemplate', 'office-grace')
        ->set('policyCode', '')
        ->set('policyName', '')
        ->call('savePolicyGroup')
        ->assertHasErrors(['policyCode', 'policyName'])
        ->assertSee('Fix these before saving:');
});

it('returns to the list when cancelling a policy edit', function (): void {
    $user = createAdminUser();
    $policy = AttendancePolicyGroup::query()->create([
        'company_id' => $user->company_id,
        'code' => 'CANCEL_ME',
        'name' => 'Cancel me',
        'effective_from' => '2026-01-01',
    ]);

    $this->actingAs($user);

    Livewire::test(PolicyGroups::class)
        ->call('editPolicyGroup', $policy->id)
        ->assertSet('mode', 'form')
        ->set('policyGraceIn', '99')
        ->call('cancelPolicyEdit')
        ->assertSet('mode', 'list')
        ->assertSet('editingPolicyGroupId', null);

    expect($policy->refresh()->lateness_rules['grace']['in'] ?? 0)->not->toBe(99);
});

it('lets managers upload and download attendance policy templates as JSON', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    $template = [
        'schema' => 'belimbing.attendance.policy-template.v1',
        'code' => 'json_policy',
        'name' => 'JSON policy',
        'work_rounding_method' => 'nearest',
        'work_rounding_minutes' => 15,
        'lateness_rounding_method' => 'ceiling',
        'lateness_rounding_minutes' => 5,
        'grace_in' => 7,
        'early_ot_minimum' => 45,
        'late_ot_minimum' => 45,
        'normal_ot_pay_item' => 'overtime',
        'lateness_pay_item' => 'lateness_deduction',
    ];

    Livewire::test(PolicyGroups::class)
        ->call('startNewPolicy')
        ->assertSee('Templates')
        ->assertSee('Upload Template')
        ->assertDontSee('Identification')
        ->set('policyTemplateUpload', UploadedFile::fake()->createWithContent('policy-template.json', json_encode($template)))
        ->call('importPolicyTemplate')
        ->assertHasNoErrors()
        ->assertSee('Policy template loaded.')
        ->assertSee('Identification')
        ->assertSet('mode', 'form')
        ->assertSet('showPolicyBuilderForm', true)
        ->assertSet('policyCode', 'JSON_POLICY')
        ->assertSet('policyGraceIn', '7');
});

it('downloads policy template JSON directly from the list without entering the builder', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'EXPORT_ME',
        'name' => 'Export me',
        'effective_from' => '2026-01-01',
        'currency' => 'MYR',
    ]);

    $this->actingAs($user);

    Livewire::test(PolicyGroups::class)
        ->call('exportPolicyGroupTemplate', $policyGroup->id)
        ->assertSee('Policy template JSON ready to download from EXPORT_ME.')
        ->assertSet('policyTemplateExportJson', fn (string $json): bool => str_contains($json, 'belimbing.attendance.policy-template.v1') && str_contains($json, 'EXPORT_ME'));
});

it('lets managers create attendance allowance rules', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'STD',
        'name' => 'Standard',
        'effective_from' => '2026-01-01',
    ]);

    $this->actingAs($user);

    $component = Livewire::test(AllowanceRules::class)
        ->assertSet('mode', 'list')
        ->assertDontSee('Best for')
        ->call('startNewAllowanceRule')
        ->assertSet('mode', 'form')
        ->assertSee('Best for')
        ->assertDontSee('Identification')
        ->call('useAllowanceTemplate', 'late-out-transport')
        ->assertSee('Identification')
        ->set('allowancePolicyGroupId', (string) $policyGroup->id)
        ->set('allowanceCode', 'night_allowance')
        ->set('allowanceName', 'Night allowance')
        ->set('allowanceAmount', '25.00')
        ->set('allowanceConditionPreset', 'clock_out_after')
        ->set('allowanceClockOutAfter', '22:00')
        ->set('allowanceEffectiveFrom', '2026-01-01')
        ->call('saveAllowanceRule')
        ->assertHasNoErrors()
        ->assertSet('mode', 'list')
        ->assertSee('Allowance rule saved.')
        ->assertSee('NIGHT_ALLOWANCE');

    $rule = AttendanceAllowanceRule::query()
        ->where('company_id', $company->id)
        ->where('code', 'NIGHT_ALLOWANCE')
        ->firstOrFail();

    expect($rule->attendance_policy_group_id)->toBe($policyGroup->id)
        ->and($rule->condition_rows[0]['amount'])->toBe(25)
        ->and($rule->condition_rows[0]['predicate'])->toBe(['clock_out_after' => '22:00']);

    $component
        ->call('editAllowanceRule', $rule->id)
        ->assertSet('mode', 'form')
        ->assertDontSee('Best for')
        ->set('allowanceAmount', '30.00')
        ->call('saveAllowanceRule')
        ->assertHasNoErrors();

    expect($rule->refresh()->condition_rows[0]['amount'])->toBe(30);

    $component
        ->call('deleteAllowanceRule', $rule->id)
        ->assertSee('Allowance rule deleted.');

    expect(AttendanceAllowanceRule::query()->whereKey($rule->id)->exists())->toBeFalse();
});

it('lets managers duplicate attendance allowance rules without binding template scope', function (): void {
    $user = createAdminUser();
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $user->company_id,
        'code' => 'STD',
        'name' => 'Standard',
        'effective_from' => '2026-01-01',
    ]);
    $rule = AttendanceAllowanceRule::query()->create([
        'company_id' => $user->company_id,
        'attendance_policy_group_id' => $policyGroup->id,
        'code' => 'MEAL',
        'name' => 'Meal allowance',
        'allowance_type' => AttendanceAllowanceRule::TYPE_DAILY,
        'resolution_method' => AttendanceAllowanceRule::RESOLUTION_SUM,
        'condition_rows' => [
            ['description' => 'Worked time', 'amount' => 10, 'predicate' => ['min_worked_minutes' => 480]],
        ],
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]);

    $this->actingAs($user);

    Livewire::test(AllowanceRules::class)
        ->call('duplicateAllowanceRule', $rule->id)
        ->assertSet('mode', 'form')
        ->assertSet('editingAllowanceRuleId', null)
        ->assertSet('allowanceCode', 'MEAL_COPY')
        ->assertSet('allowanceName', 'Meal allowance Copy')
        ->assertSet('allowanceStatus', 'inactive')
        ->assertSet('allowancePolicyGroupId', (string) $policyGroup->id)
        ->assertDontSee('Best for')
        ->set('allowancePolicyGroupId', '')
        ->call('saveAllowanceRule')
        ->assertHasNoErrors()
        ->assertSet('mode', 'list');

    $copy = AttendanceAllowanceRule::query()
        ->where('company_id', $user->company_id)
        ->where('code', 'MEAL_COPY')
        ->firstOrFail();

    expect($copy->attendance_policy_group_id)->toBeNull()
        ->and($copy->condition_rows[0]['predicate'])->toBe(['min_worked_minutes' => 480])
        ->and($copy->status)->toBe('inactive');
});

it('lets managers create roster assignments from the guided roster builder', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $policyGroup = attendancePolicyGroupForOperationsTest($company);
    $shift = attendanceShiftTemplateForOperationsTest($company);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->assertSee('Roster')
        ->set('selectedRosterEmployeeIds', [(string) $employee->id])
        ->set('rosterShiftTemplateId', (string) $shift->id)
        ->set('rosterPolicyGroupId', (string) $policyGroup->id)
        ->set('rosterEffectiveFrom', '2026-06-01')
        ->set('rosterEffectiveTo', '2026-06-30')
        ->call('saveRosterAssignment')
        ->assertHasNoErrors()
        ->assertSee('Roster assignment saved.')
        ->assertSee($employee->full_name);

    $assignment = AttendanceRosterAssignment::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->firstOrFail();

    expect($assignment->attendance_shift_template_id)->toBe($shift->id)
        ->and($assignment->attendance_policy_group_id)->toBe($policyGroup->id);
});

it('lets managers bulk-create roster assignments from filtered employee selections', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $direct = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id,
        'type' => PeopleReferenceEntry::TYPE_WORKFORCE_CLASS,
        'code' => 'DIRECT',
        'name' => 'Direct Labor',
    ]);
    $office = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id,
        'type' => PeopleReferenceEntry::TYPE_WORKFORCE_CLASS,
        'code' => 'OFFICE',
        'name' => 'Office Labor',
    ]);
    $productionEmployees = Employee::factory()->active()->count(3)->create(['company_id' => $company->id]);
    $officeEmployee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $productionEmployees->each(fn (Employee $employee) => EmployeeWorkProfile::query()->create([
        'employee_id' => $employee->id,
        'workforce_class_id' => $direct->id,
        'pay_rate_type' => 'hourly',
    ]));
    EmployeeWorkProfile::query()->create([
        'employee_id' => $officeEmployee->id,
        'workforce_class_id' => $office->id,
        'pay_rate_type' => 'monthly',
    ]);
    $policyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'STD',
        'name' => 'Standard',
        'effective_from' => '2026-01-01',
    ]);
    $shift = AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'DAY',
        'name' => 'Day Shift',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
    ]);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->set('rosterWorkforceClassId', (string) $direct->id)
        ->call('selectAllFilteredRosterEmployees')
        ->assertSet('rosterSelectAllFiltered', true)
        ->set('rosterShiftTemplateId', (string) $shift->id)
        ->set('rosterPolicyGroupId', (string) $policyGroup->id)
        ->set('rosterEffectiveFrom', '2026-07-01')
        ->set('rosterEffectiveTo', '2026-07-31')
        ->call('saveRosterAssignment')
        ->assertHasNoErrors()
        ->assertSee('3 roster assignments saved.');

    expect(AttendanceRosterAssignment::query()
        ->where('company_id', $company->id)
        ->whereIn('employee_id', $productionEmployees->pluck('id'))
        ->count())->toBe(3)
        ->and(AttendanceRosterAssignment::query()
            ->where('company_id', $company->id)
            ->where('employee_id', $officeEmployee->id)
            ->exists())->toBeFalse();
});

it('skips overlapping employees while saving valid bulk roster assignments', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employees = Employee::factory()->active()->count(2)->create(['company_id' => $company->id]);
    $policyGroup = attendancePolicyGroupForOperationsTest($company);
    $shift = attendanceShiftTemplateForOperationsTest($company);

    attendanceRosterAssignmentForOperationsTest($company, $employees[0], $shift, $policyGroup, [
        'effective_from' => '2026-07-01',
        'effective_to' => '2026-07-31',
        'publish_state' => 'published',
    ]);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->set('selectedRosterEmployeeIds', $employees->pluck('id')->map(fn (int $id): string => (string) $id)->all())
        ->set('rosterShiftTemplateId', (string) $shift->id)
        ->set('rosterPolicyGroupId', (string) $policyGroup->id)
        ->set('rosterEffectiveFrom', '2026-07-15')
        ->set('rosterEffectiveTo', '2026-08-15')
        ->call('saveRosterAssignment')
        ->assertHasNoErrors()
        ->assertSee('Roster assignment saved. 1 skipped because of existing roster overlaps.');

    expect(AttendanceRosterAssignment::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employees[0]->id)
        ->count())->toBe(1)
        ->and(AttendanceRosterAssignment::query()
            ->where('company_id', $company->id)
            ->where('employee_id', $employees[1]->id)
            ->count())->toBe(1);
});

it('shows roster assignments from saved data in the grid', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employees = Employee::factory()->active()->count(2)->create(['company_id' => $company->id]);
    $policyGroup = attendancePolicyGroupForOperationsTest($company);
    $dayShift = attendanceShiftTemplateForOperationsTest($company);
    $nightShift = attendanceShiftTemplateForOperationsTest($company, [
        'code' => 'NIGHT',
        'name' => 'Night Shift',
        'starts_at' => '20:00:00',
        'ends_at' => '05:00:00',
        'crosses_midnight' => true,
    ]);

    attendanceRosterAssignmentForOperationsTest($company, $employees[0], $dayShift, $policyGroup, [
        'effective_from' => '2026-08-01',
        'effective_to' => '2026-08-07',
        'publish_state' => 'published',
    ]);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->set('listWeekAnchor', '2026-08-03')
        ->assertSee('DAY')
        ->set('selectedRosterEmployeeIds', [(string) $employees[1]->id])
        ->set('rosterShiftTemplateId', (string) $nightShift->id)
        ->set('rosterPolicyGroupId', (string) $policyGroup->id)
        ->set('rosterEffectiveFrom', '2026-08-01')
        ->set('rosterEffectiveTo', '2026-08-07')
        ->call('saveRosterAssignment')
        ->assertHasNoErrors()
        ->assertSee('NIGHT');
});

it('narrows the list-mode calendar via the filter prose without flipping into form mode', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);

    $productionType = DepartmentType::query()->create([
        'code' => 'unit-prod',
        'name' => 'Production unit',
        'category' => 'operations',
        'is_active' => true,
    ]);
    $officeType = DepartmentType::query()->create([
        'code' => 'unit-office',
        'name' => 'Office unit',
        'category' => 'operations',
        'is_active' => true,
    ]);

    $production = Department::query()->create([
        'company_id' => $company->id,
        'department_type_id' => $productionType->id,
    ]);
    $office = Department::query()->create([
        'company_id' => $company->id,
        'department_type_id' => $officeType->id,
    ]);

    Employee::factory()->active()->create([
        'company_id' => $company->id,
        'department_id' => $production->id,
        'full_name' => ATTENDANCE_POLICY_PRODUCTION_EMPLOYEE_NAME,
    ]);
    Employee::factory()->active()->create([
        'company_id' => $company->id,
        'department_id' => $office->id,
        'full_name' => ATTENDANCE_POLICY_OFFICE_EMPLOYEE_NAME,
    ]);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->assertSee(ATTENDANCE_POLICY_PRODUCTION_EMPLOYEE_NAME)
        ->assertSee(ATTENDANCE_POLICY_OFFICE_EMPLOYEE_NAME)
        ->assertSee('all departments')
        ->assertViewHas('filteredEmployeeCount', 2)
        ->set('rosterDepartmentId', (string) $production->id)
        ->assertSee(ATTENDANCE_POLICY_PRODUCTION_EMPLOYEE_NAME)
        ->assertViewHas('filteredEmployeeCount', 1)
        ->assertSee('Production')
        ->call('clearRosterFilters')
        ->assertViewHas('filteredEmployeeCount', 2)
        ->assertSee('all departments');
});

it('applies a per-cell shift override from list mode without requiring form state', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $policyGroup = attendancePolicyGroupForOperationsTest($company);
    $shift = attendanceShiftTemplateForOperationsTest($company);

    $thisWeekStart = CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY);
    $targetDate = $thisWeekStart->addDay()->toDateString();

    $this->actingAs($user);

    // List mode never sets rosterShiftTemplateId / rosterPolicyGroupId, so the
    // call has to carry the explicit choice. Previously it silently failed
    // because saveCellOverride() depended on the form state.
    Livewire::test(Rosters::class)
        ->assertSet('rosterShiftTemplateId', '')
        ->assertSet('rosterPolicyGroupId', '')
        ->call('saveCellOverride', $employee->id, $targetDate, $shift->id, $policyGroup->id)
        ->assertHasNoErrors()
        ->assertSee('DAY')
        ->assertSee('Roster cell override saved.');

    $assignment = AttendanceRosterAssignment::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->whereDate('effective_from', $targetDate)
        ->first();

    expect($assignment)->not->toBeNull()
        ->and((int) $assignment->attendance_shift_template_id)->toBe($shift->id)
        ->and((int) $assignment->attendance_policy_group_id)->toBe($policyGroup->id);
});

it('flashes a soft error when the cell override is called without shift or policy', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->call('saveCellOverride', $employee->id, '2026-09-10', null, null)
        ->assertHasNoErrors()
        ->assertSee('Pick a shift and a policy before applying the override.');

    expect(AttendanceRosterAssignment::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
});

it('records a cell override on an existing roster assignment and bumps revision', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $policyGroup = attendancePolicyGroupForOperationsTest($company, ['name' => 'Standard Attendance']);
    $dayShift = attendanceShiftTemplateForOperationsTest($company);
    $eveShift = attendanceShiftTemplateForOperationsTest($company, [
        'code' => 'EVE',
        'name' => 'Evening Shift',
        'starts_at' => '14:00:00',
        'ends_at' => '22:00:00',
    ]);

    $assignment = attendanceRosterAssignmentForOperationsTest($company, $employee, $dayShift, $policyGroup, [
        'effective_from' => '2026-09-01',
        'effective_to' => '2026-09-30',
    ]);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->call('saveCellOverride', $employee->id, '2026-09-15', $eveShift->id, $policyGroup->id)
        ->assertHasNoErrors()
        ->assertSee('Roster cell override saved.');

    $assignment->refresh();
    $override = collect($assignment->exceptions)->firstWhere('date', '2026-09-15');

    expect((int) $assignment->revision)->toBe(2)
        ->and($override)->not->toBeNull()
        ->and((int) $override['attendance_shift_template_id'])->toBe($eveShift->id);
});

it('switches the list-mode calendar between week and month scopes', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    Employee::factory()->active()->create(['company_id' => $company->id]);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->assertSet('listScope', 'week')
        ->call('setListScope', 'month')
        ->assertSet('listScope', 'month')
        ->assertSee('Month')
        ->assertSee('This month')
        ->tap(function ($component) {
            $days = $component->viewData('rosterGridDays');
            expect(count($days))->toBeGreaterThanOrEqual(28)
                ->and(count($days))->toBeLessThanOrEqual(31);
        })
        ->call('setListScope', 'week')
        ->assertSet('listScope', 'week')
        ->assertViewHas('rosterGridDays', fn ($days) => count($days) === 7);
});

it('opens to the calendar as the list-mode primary surface and supports week navigation', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $policyGroup = attendancePolicyGroupForOperationsTest($company, ['name' => 'Standard Attendance']);
    $shift = attendanceShiftTemplateForOperationsTest($company);

    $thisWeekStart = CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY);

    attendanceRosterAssignmentForOperationsTest($company, $employee, $shift, $policyGroup, [
        'effective_from' => $thisWeekStart->toDateString(),
        'effective_to' => $thisWeekStart->addDays(6)->toDateString(),
        'publish_state' => 'published',
    ]);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->assertSee('Calendar')
        ->assertSee('Records')
        ->assertSee('Employee')
        ->assertSee($employee->full_name)
        ->assertSee('DAY')
        ->call('goToNextWeek')
        ->assertSet('listWeekAnchor', $thisWeekStart->addDays(7)->toDateString())
        ->call('goToPreviousWeek')
        ->assertSet('listWeekAnchor', $thisWeekStart->toDateString())
        ->call('goToThisWeek')
        ->assertSet('listWeekAnchor', '');
});

it('supports roster cell overrides and resolves them into attendance days', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $otherCompany = Company::factory()->minimal()->create();
    $policyGroup = attendancePolicyGroupForOperationsTest($company);
    $dayShift = attendanceShiftTemplateForOperationsTest($company);
    $nightShift = attendanceShiftTemplateForOperationsTest($company, [
        'code' => 'NIGHT',
        'name' => 'Night Shift',
        'starts_at' => '20:00:00',
        'ends_at' => '05:00:00',
        'crosses_midnight' => true,
    ]);
    $foreignPolicyGroup = AttendancePolicyGroup::query()->create([
        'company_id' => $otherCompany->id,
        'code' => 'FOREIGN',
        'name' => 'Foreign',
        'effective_from' => '2026-01-01',
    ]);
    attendanceRosterAssignmentForOperationsTest($company, $employee, $dayShift, $policyGroup, [
        'effective_from' => '2026-08-01',
        'effective_to' => '2026-08-07',
        'publish_state' => 'published',
    ]);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->set('rosterShiftTemplateId', (string) $nightShift->id)
        ->set('rosterPolicyGroupId', (string) $policyGroup->id)
        ->call('saveCellOverride', $employee->id, '2026-08-03')
        ->assertHasNoErrors();

    $day = app(AttendanceDayResolverService::class)->resolve($employee, '2026-08-03');

    expect($day->attendance_shift_template_id)->toBe($nightShift->id);

    $tamperedEmployee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $tamperedAssignment = attendanceRosterAssignmentForOperationsTest($company, $tamperedEmployee, $dayShift, $policyGroup, [
        'effective_from' => '2026-08-01',
        'effective_to' => '2026-08-07',
        'publish_state' => 'published',
        'exceptions' => [[
            'date' => '2026-08-04',
            'attendance_shift_template_id' => $nightShift->id,
            'attendance_policy_group_id' => $foreignPolicyGroup->id,
        ]],
    ]);

    $tamperedDay = app(AttendanceDayResolverService::class)->resolve($tamperedEmployee, '2026-08-04');

    expect($tamperedAssignment->exists())->toBeTrue()
        ->and($tamperedDay->attendance_shift_template_id)->toBe($nightShift->id)
        ->and($tamperedDay->attendance_policy_group_id)->toBe($policyGroup->id);
});

it('rejects cell overrides for employees and roster dimensions outside the current company', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $otherCompany = Company::factory()->minimal()->create();
    $foreignEmployee = Employee::factory()->active()->create(['company_id' => $otherCompany->id]);
    $policyGroup = attendancePolicyGroupForOperationsTest($company);
    $shift = attendanceShiftTemplateForOperationsTest($company);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->set('rosterShiftTemplateId', (string) $shift->id)
        ->set('rosterPolicyGroupId', (string) $policyGroup->id)
        ->call('saveCellOverride', $foreignEmployee->id, '2026-08-03')
        ->assertSee('Pick a shift and a policy before applying the override.');

    expect(AttendanceRosterAssignment::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $foreignEmployee->id)
        ->exists())->toBeFalse();
});

it('imports spreadsheet roster rows without disturbing unrelated draft assignments', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employee = Employee::factory()->active()->create(['company_id' => $company->id, 'employee_number' => 'EMP-SPREAD']);
    $otherEmployee = Employee::factory()->active()->create(['company_id' => $company->id, 'employee_number' => 'EMP-OTHER']);
    $policyGroup = attendancePolicyGroupForOperationsTest($company);
    $shift = attendanceShiftTemplateForOperationsTest($company);
    $unselectedDraft = attendanceRosterAssignmentForOperationsTest($company, $otherEmployee, $shift, $policyGroup, [
        'effective_from' => '2026-09-01',
        'effective_to' => '2026-09-01',
    ]);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->set('spreadsheetRosterRows', "EMP-SPREAD,2026-09-01,DAY,STD,Line one\n")
        ->call('importSpreadsheetRosterRows')
        ->assertHasNoErrors()
        ->assertSee('Spreadsheet roster import saved');

    $assignment = AttendanceRosterAssignment::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->firstOrFail();

    expect($assignment->attendance_shift_template_id)->toBe($shift->id)
        ->and($assignment->attendance_policy_group_id)->toBe($policyGroup->id)
        ->and($unselectedDraft->fresh()->publish_state)->toBe('draft');
});

it('emits stable roster operator JSON from the attendance roster command', function (): void {
    $company = Company::factory()->minimal()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $policyGroup = attendancePolicyGroupForOperationsTest($company);
    $shift = attendanceShiftTemplateForOperationsTest($company);
    attendanceRosterAssignmentForOperationsTest($company, $employee, $shift, $policyGroup, [
        'effective_from' => '2026-09-01',
        'effective_to' => '2026-09-01',
    ]);

    Artisan::call('blb:attendance:roster', [
        'action' => 'publish-dry-run',
        '--company' => $company->id,
        '--from' => '2026-09-01',
        '--to' => '2026-09-01',
    ]);

    $payload = json_decode(Artisan::output(), true);

    expect($payload['status'])->toBe('ok')
        ->and($payload['summary']['drafts'])->toBe(1)
        ->and($payload['publish_preview'][0]['shift'])->toBe('DAY');
});

it('returns roster command validation errors as stable JSON', function (): void {
    $exitCode = Artisan::call('blb:attendance:roster', [
        'action' => 'publish-dry-run',
    ]);

    $payload = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(1)
        ->and($payload['status'])->toBe('error')
        ->and(collect($payload['findings'])->pluck('code')->all())->toContain('company_required', 'from_date_required', 'to_date_required');
});

it('lets managers build shift templates inline from guided templates and import JSON', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    startAttendanceTemplateBuilderForOperationsTest(
        ShiftTemplates::class,
        'startNewShift',
        'useShiftTemplate',
        'night-shift',
        'Best for',
        ATTENDANCE_SHIFT_CODE_LABEL,
    )
        ->assertSet('shiftCode', 'NIGHT_SHIFT')
        ->set('shiftCode', 'NIGHT_MAIN')
        ->set('shiftName', 'Night Main')
        ->call('saveShiftTemplate')
        ->assertHasNoErrors()
        ->assertSet('mode', 'list')
        ->assertSee('Shift template saved.');

    $shift = AttendanceShiftTemplate::query()
        ->where('company_id', $user->company_id)
        ->where('code', 'NIGHT_MAIN')
        ->firstOrFail();

    expect($shift->crosses_midnight)->toBeTrue()
        ->and($shift->expected_work_minutes)->toBe(660)
        ->and($shift->break_windows[0]['starts_at'])->toBe('00:00')
        ->and($shift->punchWindows()->where('event_type', AttendancePunchWindow::TYPE_IN)->exists())->toBeTrue()
        ->and($shift->punchWindows()->where('event_type', AttendancePunchWindow::TYPE_OUT)->exists())->toBeTrue();

    Livewire::test(ShiftTemplates::class)
        ->call('startNewShift')
        ->set('shiftTemplateUpload', UploadedFile::fake()->createWithContent('shift-template.json', json_encode([
            'schema' => 'belimbing.attendance.shift-template.v1',
            'code' => 'IMPORT_DAY',
            'name' => 'Imported Day',
            'starts_at' => '09:00',
            'ends_at' => '18:00',
            'expected_work_minutes' => 480,
            'break_windows' => [['starts_at' => '13:00', 'ends_at' => '14:00']],
            'punch_windows' => [
                'in' => ['before_minutes' => 30, 'after_minutes' => 10],
                'out' => ['before_minutes' => 10, 'after_minutes' => 90],
            ],
            'cross_midnight_attribution' => 'shift_start_date',
        ])))
        ->call('importShiftTemplate')
        ->assertHasNoErrors()
        ->assertSet('mode', 'form')
        ->assertSet('shiftCode', 'IMPORT_DAY')
        ->assertSet('shiftBreaks.0.starts_at', '13:00')
        ->assertSet('shiftInWindowBeforeMinutes', '30');
});

it('persists multiple breaks with per-break paid flag and emits second break punch windows', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    Livewire::test(ShiftTemplates::class)
        ->call('startNewShift')
        ->set('shiftCode', 'PROD_12H')
        ->set('shiftName', 'Production 12h')
        ->set('shiftStartsAt', '07:00')
        ->set('shiftEndsAt', '19:00')
        ->set('shiftExpectedWorkMinutes', '660')
        ->set('shiftBreaks.0', ['label' => 'Lunch', 'starts_at' => '12:00', 'ends_at' => '13:00', 'paid' => false])
        ->call('addShiftBreak')
        ->set('shiftBreaks.1', ['label' => 'Tea', 'starts_at' => '15:30', 'ends_at' => '15:45', 'paid' => true])
        ->call('saveShiftTemplate')
        ->assertHasNoErrors();

    $shift = AttendanceShiftTemplate::query()->where('code', 'PROD_12H')->firstOrFail();
    expect($shift->break_windows)->toHaveCount(2)
        ->and($shift->break_windows[0])->toMatchArray(['label' => 'Lunch', 'starts_at' => '12:00', 'ends_at' => '13:00', 'paid' => false])
        ->and($shift->break_windows[1])->toMatchArray(['label' => 'Tea', 'starts_at' => '15:30', 'ends_at' => '15:45', 'paid' => true]);

    $eventTypes = $shift->punchWindows()->pluck('event_type')->all();
    expect($eventTypes)
        ->toContain(AttendancePunchWindow::TYPE_BREAK_OUT)
        ->toContain(AttendancePunchWindow::TYPE_BREAK_IN)
        ->toContain(AttendancePunchWindow::TYPE_BREAK_OUT_2)
        ->toContain(AttendancePunchWindow::TYPE_BREAK_IN_2);
});

it('caps the breaks form at two entries', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    Livewire::test(ShiftTemplates::class)
        ->call('startNewShift')
        ->call('addShiftBreak')
        ->call('addShiftBreak')
        ->tap(fn ($component) => expect($component->get('shiftBreaks'))->toHaveCount(2));
});

it('restores shift edit mode from the URL', function (): void {
    $user = createAdminUser();
    $shift = AttendanceShiftTemplate::query()->create([
        'company_id' => $user->company_id,
        'code' => 'URL_SHIFT',
        'name' => 'URL shift',
        'starts_at' => '06:00:00',
        'ends_at' => '14:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]);

    Livewire::actingAs($user)
        ->withQueryParams(['shift' => $shift->id])
        ->test(ShiftTemplates::class)
        ->assertSet('mode', 'form')
        ->assertSet('editingShiftTemplateId', $shift->id)
        ->assertSet('shiftCode', 'URL_SHIFT')
        ->assertSet('shiftStartsAt', '06:00')
        ->assertSee(ATTENDANCE_SHIFT_CODE_LABEL);
});

it('restores shift create mode and selected template from the URL', function (): void {
    $user = createAdminUser();

    Livewire::actingAs($user)
        ->withQueryParams(['mode' => 'form', 'template' => 'night-shift'])
        ->test(ShiftTemplates::class)
        ->assertSet('mode', 'form')
        ->assertSet('editingShiftTemplateId', null)
        ->assertSet('selectedShiftTemplateKey', 'night-shift')
        ->assertSet('showShiftBuilderForm', true)
        ->assertSet('shiftCode', 'NIGHT_SHIFT')
        ->assertSet('shiftStartsAt', '20:00')
        ->assertSee(ATTENDANCE_SHIFT_CODE_LABEL);
});

it('toggles shift template status from the list', function (): void {
    $user = createAdminUser();
    $shift = AttendanceShiftTemplate::query()->create([
        'company_id' => $user->company_id,
        'code' => 'TOGGLE_ME',
        'name' => 'Toggle me',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]);

    $this->actingAs($user);

    Livewire::test(ShiftTemplates::class)
        ->call('toggleShiftStatus', $shift->id)
        ->assertHasNoErrors()
        ->assertSee('Shift status updated.');

    expect($shift->refresh()->status)->toBe('inactive');
});

it('uses focused titles for each attendance setup page', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    Livewire::test(PolicyGroups::class)
        ->assertSee('Policy Groups')
        ->assertDontSee('Attendance Days')
        ->assertDontSee('Search employee...')
        ->assertDontSee('Settings areas')
        ->assertDontSee('Attendance Settings');

    // List mode hides templates; entering form mode reveals them.
    Livewire::test(ShiftTemplates::class)
        ->assertSet('mode', 'list')
        ->assertSee('Shifts')
        ->assertDontSee('Best for')
        ->call('startNewShift')
        ->assertSee('Best for');
});

it('keeps operational timecard controls off the approvals surface', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    Livewire::test(Approvals::class)
        ->assertSee('Overtime Queue')
        ->assertDontSee('Attendance Days')
        ->assertDontSee('Search employee...');
});

it('saves batch cell overrides across a date range in one call', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $policyGroup = attendancePolicyGroupForOperationsTest($company);
    $shift = attendanceShiftTemplateForOperationsTest($company);

    $thisWeekStart = CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY);
    $dates = [$thisWeekStart->toDateString(), $thisWeekStart->addDay()->toDateString(), $thisWeekStart->addDays(2)->toDateString()];

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->call('saveCellOverrides', [
            ['employee_id' => $employee->id, 'date' => $dates[0], 'shift_template_id' => $shift->id, 'policy_group_id' => $policyGroup->id],
            ['employee_id' => $employee->id, 'date' => $dates[1], 'shift_template_id' => $shift->id, 'policy_group_id' => $policyGroup->id],
            ['employee_id' => $employee->id, 'date' => $dates[2], 'shift_template_id' => $shift->id, 'policy_group_id' => $policyGroup->id],
        ])
        ->assertHasNoErrors()
        ->assertSee('3 cell overrides saved.');

    expect(AttendanceRosterAssignment::query()->where('company_id', $company->id)->where('employee_id', $employee->id)->count())->toBe(3);
});

it('batch clear removes draft cell assignments', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $policyGroup = attendancePolicyGroupForOperationsTest($company);
    $shift = attendanceShiftTemplateForOperationsTest($company);

    $date = CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY)->toDateString();

    $assignment = AttendanceRosterAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_shift_template_id' => $shift->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'effective_from' => $date,
        'effective_to' => $date,
        'publish_state' => 'draft',
        'lock_state' => 'open',
        'revision' => 1,
        'exceptions' => [],
        'metadata' => [],
    ]);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->call('saveCellOverrides', [
            ['employee_id' => $employee->id, 'date' => $date, 'shift_template_id' => 0, 'policy_group_id' => 0],
        ])
        ->assertHasNoErrors();

    expect(AttendanceRosterAssignment::query()->find($assignment->id))->toBeNull();
});

it('batch overrides silently skips entries outside the grid period or from other companies', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $otherCompany = Company::factory()->create();
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $foreignEmployee = Employee::factory()->active()->create(['company_id' => $otherCompany->id]);
    $policyGroup = attendancePolicyGroupForOperationsTest($company);
    $shift = attendanceShiftTemplateForOperationsTest($company);

    $thisWeekStart = CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY);

    $this->actingAs($user);

    Livewire::test(Rosters::class)
        ->call('saveCellOverrides', [
            // Foreign company employee — should be skipped
            ['employee_id' => $foreignEmployee->id, 'date' => $thisWeekStart->toDateString(), 'shift_template_id' => $shift->id, 'policy_group_id' => $policyGroup->id],
            // Date outside grid period (2 years in the future) — skipped
            ['employee_id' => $employee->id, 'date' => $thisWeekStart->addYears(2)->toDateString(), 'shift_template_id' => $shift->id, 'policy_group_id' => $policyGroup->id],
        ])
        ->assertHasNoErrors();

    expect(AttendanceRosterAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

/**
 * Create a user with only `people.attendance.roster.view` (My Schedule mode).
 * The user's `employee_id` is set to $employee->id.
 */
function createRosterViewOnlyUser(Company $company, Employee $employee): User
{
    setupAuthzRoles();

    $role = Role::query()->create([
        'company_id' => null,
        'code' => 'roster_view_only_test_'.$company->id,
        'name' => 'Roster View Only',
        'is_system' => false,
        'grant_all' => false,
    ]);

    DB::table('base_authz_role_capabilities')->insert([
        'role_id' => $role->id,
        'capability_key' => 'people.attendance.roster.view',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
    ]);

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);

    return $user;
}

it('renders My Schedule filtered to the current employee when the user lacks attendance.manage', function (): void {
    $adminUser = createAdminUser();
    $company = Company::query()->findOrFail($adminUser->company_id);

    $myEmployee = Employee::factory()->active()->create(['company_id' => $company->id, 'full_name' => 'My Self']);
    $otherEmployee = Employee::factory()->active()->create(['company_id' => $company->id, 'full_name' => 'Other Person']);

    $viewOnlyUser = createRosterViewOnlyUser($company, $myEmployee);

    $policyGroup = attendancePolicyGroupForOperationsTest($company);
    $shift = attendanceShiftTemplateForOperationsTest($company);
    $monday = CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY)->toDateString();

    AttendanceRosterAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $myEmployee->id,
        'attendance_shift_template_id' => $shift->id,
        'attendance_policy_group_id' => $policyGroup->id,
        'effective_from' => $monday,
        'effective_to' => $monday,
        'publish_state' => 'published',
        'lock_state' => 'open',
        'revision' => 1,
        'exceptions' => [],
        'metadata' => [],
    ]);

    $this->actingAs($viewOnlyUser);

    Livewire::test(Rosters::class)
        ->assertSee('My Schedule')
        ->assertSee($myEmployee->full_name)
        ->assertDontSee($otherEmployee->full_name);
});

it('lets an employee acknowledge their schedule for the current period', function (): void {
    $adminUser = createAdminUser();
    $company = Company::query()->findOrFail($adminUser->company_id);
    $myEmployee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $viewOnlyUser = createRosterViewOnlyUser($company, $myEmployee);

    $monday = CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    $sunday = CarbonImmutable::today()->endOfWeek(CarbonImmutable::SUNDAY)->toDateString();

    $this->actingAs($viewOnlyUser);

    expect(AttendanceRosterAcknowledgment::query()
        ->where('employee_id', $myEmployee->id)
        ->where('period_start', $monday)
        ->exists()
    )->toBeFalse();

    Livewire::test(Rosters::class)
        ->call('acknowledgeSchedule', $monday, $sunday)
        ->assertHasNoErrors();

    expect(AttendanceRosterAcknowledgment::query()
        ->where('employee_id', $myEmployee->id)
        ->where('period_start', $monday)
        ->where('period_end', $sunday)
        ->exists()
    )->toBeTrue();
});

it('resets acknowledgment when a published cell override is saved for an employee', function (): void {
    $adminUser = createAdminUser();
    $company = Company::query()->findOrFail($adminUser->company_id);
    [$employee, $policyGroup, $shift, $monday, $sunday] = publishedRosterScenarioForOperationsTest($company);

    AttendanceRosterAcknowledgment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_start' => $monday,
        'period_end' => $sunday,
        'acknowledged_at' => now(),
    ]);

    expect(AttendanceRosterAcknowledgment::query()->where('employee_id', $employee->id)->count())->toBe(1);

    $this->actingAs($adminUser);

    Livewire::test(Rosters::class)
        ->call('saveCellOverride', $employee->id, $monday, $shift->id, $policyGroup->id)
        ->assertHasNoErrors();

    expect(AttendanceRosterAcknowledgment::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

// ─── 18d Roster Lock ──────────────────────────────────────────────────────────

it('locks a roster period and blocks cell override on locked dates', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    [$employee, $policyGroup, $shift, $monday, $sunday] = publishedRosterScenarioForOperationsTest($company);

    $this->actingAs($user);

    // Lock the period
    Livewire::test(Rosters::class)
        ->call('lockRosterPeriod', $monday, $sunday)
        ->assertHasNoErrors();

    expect(AttendanceRosterLock::query()
        ->where('company_id', $company->id)
        ->where('period_start', $monday)
        ->where('period_end', $sunday)
        ->whereNull('unlocked_at')
        ->exists()
    )->toBeTrue();

    // Cell override on locked date is blocked
    Livewire::test(Rosters::class)
        ->call('saveCellOverride', $employee->id, $monday, $shift->id, $policyGroup->id)
        ->assertHasNoErrors(); // no validation error, but a session flash error

    // Verify the assignment was NOT modified (no exception added)
    $assignment = AttendanceRosterAssignment::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->firstOrFail();

    expect($assignment->exceptions)->toBeEmpty();
});

it('unlocks a roster period after providing a reason', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $monday = CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    $sunday = CarbonImmutable::today()->endOfWeek(CarbonImmutable::SUNDAY)->toDateString();

    AttendanceRosterLock::query()->create([
        'company_id' => $company->id,
        'period_start' => $monday,
        'period_end' => $sunday,
        'locked_by' => $user->id,
        'locked_at' => now(),
    ]);

    $this->actingAs($user);

    // Unlock without reason fails silently (validation error on component)
    Livewire::test(Rosters::class)
        ->call('unlockRosterPeriod', $monday, $sunday, '')
        ->assertHasErrors(['unlockReason']);

    // The lock record is still active
    expect(AttendanceRosterLock::query()
        ->where('company_id', $company->id)
        ->whereNull('unlocked_at')
        ->exists()
    )->toBeTrue();

    // Unlock with a valid reason succeeds
    Livewire::test(Rosters::class)
        ->call('unlockRosterPeriod', $monday, $sunday, 'Payroll correction needed')
        ->assertHasNoErrors();

    $lock = AttendanceRosterLock::query()
        ->where('company_id', $company->id)
        ->where('period_start', $monday)
        ->first();

    expect($lock)->not->toBeNull()
        ->and($lock->unlocked_at)->not->toBeNull()
        ->and($lock->unlock_reason)->toBe('Payroll correction needed');
});

it('exposes actual attendance outcomes in actualMode for the grid', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    [$employee, , , $monday] = publishedRosterScenarioForOperationsTest($company);

    AttendanceDay::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_date' => $monday,
        'status' => AttendanceDay::STATUS_FINALIZED,
        'day_type' => 'normal',
        'worked_minutes' => 0,
        'late_minutes' => 0,
        'early_out_minutes' => 0,
        'absent_minutes' => 480,
        'expected_minutes' => 480,
        'payable_minutes' => 0,
        'break_minutes' => 0,
        'overtime_candidate_minutes' => 0,
    ]);

    $this->actingAs($user);

    $component = Livewire::test(Rosters::class)
        ->set('actualMode', true);

    $actualOutcomes = $component->get('actualMode');
    expect($actualOutcomes)->toBeTrue();

    // The component renders without errors when actualMode is on
    $component->assertHasNoErrors();
});
