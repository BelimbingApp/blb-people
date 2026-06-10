<?php

use App\Base\Audit\Models\AuditMutation;
use App\Base\Audit\Services\AuditBuffer;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;

const ROSTER_AUDIT_SETUP_EFFECTIVE_FROM = '2026-01-01';
const ROSTER_AUDIT_RANGE_START = '2026-05-15';
const ROSTER_AUDIT_RANGE_END = '2026-05-16';
const ROSTER_AUDIT_REMOVED_DATE = '2026-05-17';

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     employee: Employee,
 *     shift: AttendanceShiftTemplate,
 *     policy: AttendancePolicyGroup,
 * }
 */
function createRosterAuditSubjectFixture(): array
{
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employee = Employee::factory()->active()->create(['company_id' => $company->id]);
    $shift = AttendanceShiftTemplate::query()->create([
        'company_id' => $company->id,
        'code' => 'DAY',
        'name' => 'Day',
        'starts_at' => '08:00:00',
        'ends_at' => '17:00:00',
        'expected_work_minutes' => 480,
        'effective_from' => ROSTER_AUDIT_SETUP_EFFECTIVE_FROM,
    ]);
    $policy = AttendancePolicyGroup::query()->create([
        'company_id' => $company->id,
        'code' => 'STD',
        'name' => 'Standard',
        'effective_from' => ROSTER_AUDIT_SETUP_EFFECTIVE_FROM,
    ]);

    return compact('user', 'company', 'employee', 'shift', 'policy');
}

function flushRosterAuditBuffer(): void
{
    $buffer = app(AuditBuffer::class);
    $reflection = new ReflectionClass($buffer);
    $method = $reflection->getMethod('flush');
    $method->invoke($buffer);
}

it('writes expanded roster assignment rows to the audit subject index', function (): void {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'shift' => $shift, 'policy' => $policy] = createRosterAuditSubjectFixture();

    $this->actingAs($user);

    AttendanceRosterAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_shift_template_id' => $shift->id,
        'attendance_policy_group_id' => $policy->id,
        'effective_from' => ROSTER_AUDIT_RANGE_START,
        'effective_to' => ROSTER_AUDIT_RANGE_END,
    ]);

    flushRosterAuditBuffer();

    $rows = AuditMutation::query()
        ->where('subject_name', 'employee')
        ->where('subject_id', $employee->id)
        ->where('source', 'expanded')
        ->orderBy('subject_identifier')
        ->get();

    expect($rows)->toHaveCount(2);
    expect($rows->pluck('subject_identifier')->all())->toBe([ROSTER_AUDIT_RANGE_START, ROSTER_AUDIT_RANGE_END]);
    expect($rows->first()->new_values)->toBe(['shift_code' => 'DAY', 'policy_code' => 'STD']);
});

it('writes only changed cells when a roster assignment date range changes', function (): void {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'shift' => $shift, 'policy' => $policy] = createRosterAuditSubjectFixture();

    $this->actingAs($user);

    $assignment = AttendanceRosterAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'attendance_shift_template_id' => $shift->id,
        'attendance_policy_group_id' => $policy->id,
        'effective_from' => ROSTER_AUDIT_RANGE_START,
        'effective_to' => ROSTER_AUDIT_REMOVED_DATE,
    ]);
    flushRosterAuditBuffer();

    $assignment->update(['effective_to' => ROSTER_AUDIT_RANGE_END]);
    flushRosterAuditBuffer();

    $updatedRows = AuditMutation::query()
        ->where('subject_name', 'employee')
        ->where('subject_id', $employee->id)
        ->where('source', 'expanded')
        ->where('event', 'updated')
        ->get();

    expect($updatedRows)->toHaveCount(1);
    expect($updatedRows->first()->subject_identifier)->toBe(ROSTER_AUDIT_REMOVED_DATE);
    expect($updatedRows->first()->old_values)->toBe(['shift_code' => 'DAY', 'policy_code' => 'STD']);
    expect($updatedRows->first()->new_values)->toBeNull();
});
