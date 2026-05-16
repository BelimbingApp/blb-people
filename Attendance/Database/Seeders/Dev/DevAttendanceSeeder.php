<?php

namespace App\Modules\People\Attendance\Database\Seeders\Dev;

use App\Base\Database\Seeders\DevSeeder;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Database\Seeders\Dev\DevEmployeeSeeder;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Attendance\Models\AttendanceAbsenceBatch;
use App\Modules\People\Attendance\Models\AttendanceAbsenceBatchEntry;
use App\Modules\People\Attendance\Models\AttendanceAllowanceRule;
use App\Modules\People\Attendance\Models\AttendanceClockEvent;
use App\Modules\People\Attendance\Models\AttendanceDay;
use App\Modules\People\Attendance\Models\AttendanceGeofence;
use App\Modules\People\Attendance\Models\AttendanceGeofenceGroup;
use App\Modules\People\Attendance\Models\AttendanceOvertimeRequest;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendancePunchWindow;
use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use App\Modules\People\Attendance\Models\AttendanceRosterPattern;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use App\Modules\People\Settings\Database\Seeders\Dev\DevPeopleSettingsSeeder;
use Carbon\CarbonImmutable;

class DevAttendanceSeeder extends DevSeeder
{
    protected array $dependencies = [
        DevEmployeeSeeder::class,
        DevPeopleSettingsSeeder::class,
    ];

    protected function seed(): void
    {
        $company = $this->licenseeCompany();

        if (! $company instanceof Company) {
            return;
        }

        $employees = Employee::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->where('employee_type', '!=', 'agent')
            ->orderBy('employee_number')
            ->limit(4)
            ->get();

        if ($employees->isEmpty()) {
            return;
        }

        $dayShift = $this->seedDayShift($company);
        $nightShift = $this->seedNightShift($company);
        $policyGroup = $this->seedPolicyGroup($company);
        $this->seedAllowanceRules($company, $policyGroup);
        $pattern = $this->seedRosterPattern($company, $dayShift, $nightShift);
        $this->seedRosterAssignments($company, $employees, $pattern, $dayShift, $policyGroup);
        $geofenceGroup = $this->seedGeofences($company);
        $this->seedAttendanceDays($company, $employees, $dayShift, $policyGroup, $geofenceGroup);
        $this->seedAbsenceBatch($company, $employees);
    }

    private function seedDayShift(Company $company): AttendanceShiftTemplate
    {
        $shift = AttendanceShiftTemplate::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'day_8_5'],
            [
                'name' => 'Day Shift (8-5)',
                'starts_at' => '08:00:00',
                'ends_at' => '17:00:00',
                'crosses_midnight' => false,
                'expected_work_minutes' => 480,
                'break_windows' => [['label' => 'Lunch', 'starts_at' => '12:00', 'ends_at' => '13:00', 'paid' => false]],
                'cross_midnight_attribution' => 'shift_start_date',
                'effective_from' => '2026-01-01',
                'status' => AttendanceShiftTemplate::STATUS_ACTIVE,
                'source_system' => 'hr2000',
                'source_label' => 'TMS Group Shift',
                'source_code' => 'PROD-1',
                'metadata' => ['scenario' => 'attendance-dev'],
            ],
        );

        $this->replacePunchWindows($shift, [
            [AttendancePunchWindow::TYPE_IN, '08:00:00', '06:00:00', '10:00:00', 10],
            [AttendancePunchWindow::TYPE_BREAK_OUT, '12:00:00', '11:45:00', '12:15:00', 20],
            [AttendancePunchWindow::TYPE_BREAK_IN, '13:00:00', '12:45:00', '14:30:00', 30],
            [AttendancePunchWindow::TYPE_OUT, '17:00:00', '16:30:00', '20:00:00', 40],
        ]);

        return $shift;
    }

    private function seedNightShift(Company $company): AttendanceShiftTemplate
    {
        $shift = AttendanceShiftTemplate::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'night_8_5'],
            [
                'name' => 'Night Shift (8-5)',
                'starts_at' => '20:00:00',
                'ends_at' => '05:00:00',
                'crosses_midnight' => true,
                'expected_work_minutes' => 480,
                'break_windows' => [['label' => 'Midnight break', 'starts_at' => '00:00', 'ends_at' => '01:00', 'paid' => false]],
                'cross_midnight_attribution' => 'shift_start_date',
                'effective_from' => '2026-01-01',
                'status' => AttendanceShiftTemplate::STATUS_ACTIVE,
                'source_system' => 'hr2000',
                'source_label' => 'TMS Group Shift',
                'source_code' => 'PROD-2',
                'metadata' => ['scenario' => 'attendance-dev'],
            ],
        );

        $this->replacePunchWindows($shift, [
            [AttendancePunchWindow::TYPE_IN, '20:00:00', '18:00:00', '22:00:00', 10],
            [AttendancePunchWindow::TYPE_BREAK_OUT, '00:00:00', '23:45:00', '00:15:00', 20],
            [AttendancePunchWindow::TYPE_BREAK_IN, '01:00:00', '00:45:00', '02:30:00', 30],
            [AttendancePunchWindow::TYPE_OUT, '05:00:00', '04:30:00', '08:00:00', 40],
        ]);

        return $shift;
    }

    /** @param  list<array{0:string,1:string,2:string,3:string,4:int}>  $windows */
    private function replacePunchWindows(AttendanceShiftTemplate $shift, array $windows): void
    {
        AttendancePunchWindow::query()->where('attendance_shift_template_id', $shift->id)->delete();

        foreach ($windows as [$type, $expected, $earliest, $latest, $order]) {
            AttendancePunchWindow::query()->create([
                'attendance_shift_template_id' => $shift->id,
                'event_type' => $type,
                'expected_at' => $expected,
                'earliest_at' => $earliest,
                'latest_at' => $latest,
                'required' => true,
                'exception_on_unmatched' => true,
                'sort_order' => $order,
                'metadata' => ['scenario' => 'attendance-dev'],
            ]);
        }
    }

    private function seedPolicyGroup(Company $company): AttendancePolicyGroup
    {
        return AttendancePolicyGroup::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'prod_8_5'],
            [
                'name' => 'Production (8-5)',
                'work_hour_rules' => [
                    'daily_rounding' => ['method' => 'nearest', 'minutes' => 15],
                    'daily_rated_workday_counts' => ['paid_rest_day' => false, 'paid_off_day' => false, 'paid_holiday' => false],
                    'break_treatment' => ['monthly_exclude_break_hours' => true, 'daily_exclude_break_hours' => true, 'less_break_lateness' => true],
                ],
                'lateness_rules' => [
                    'daily_rounding' => ['method' => 'ceiling', 'minutes' => 5],
                    'grace' => ['in' => 0, 'out' => 0, 'start_break' => 0, 'end_break' => 0],
                ],
                'overtime_rules' => [
                    'early_ot' => ['enabled' => true, 'minimum_minutes' => 60],
                    'late_ot' => ['enabled' => true, 'minimum_minutes' => 60],
                    'day_types' => ['normal' => true, 'holiday' => true, 'rest_day' => true, 'off_day' => true],
                    'adjustment_bands' => [
                        ['from' => 0, 'to' => 60, 'operation' => 'set', 'minutes' => 0, 'day_types' => ['normal']],
                    ],
                    'knock_off' => ['lateness' => true, 'npl' => true],
                ],
                'overtime_export_rules' => [
                    'normal' => [['lte_hours' => 2, 'pay_item_code' => 'overtime'], ['lte_hours' => null, 'pay_item_code' => 'overtime_extended']],
                    'rest_day' => [['lte_hours' => null, 'pay_item_code' => 'rest_day_overtime']],
                    'holiday' => [['lte_hours' => null, 'pay_item_code' => 'holiday_overtime']],
                ],
                'lateness_export_rules' => [
                    'monthly_rounding' => ['method' => 'ceiling', 'minutes' => 15],
                    'pay_item_code' => 'lateness_deduction',
                ],
                'currency' => 'MYR',
                'effective_from' => '2026-01-01',
                'version' => 1,
                'status' => AttendancePolicyGroup::STATUS_ACTIVE,
                'source_system' => 'hr2000',
                'source_label' => 'TMS Group',
                'source_code' => 'PROD (8-5)',
                'metadata' => ['scenario' => 'attendance-dev'],
            ],
        );
    }

    private function seedAllowanceRules(Company $company, AttendancePolicyGroup $policyGroup): void
    {
        AttendanceAllowanceRule::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'full_attendance'],
            [
                'attendance_policy_group_id' => $policyGroup->id,
                'name' => 'Full Attendance',
                'allowance_type' => AttendanceAllowanceRule::TYPE_MONTHLY,
                'ceiling_amount' => 50,
                'resolution_method' => AttendanceAllowanceRule::RESOLUTION_MIN,
                'condition_rows' => [
                    ['description' => 'Full month entitle', 'amount' => 50, 'predicate' => ['type' => 'no_absence_or_emergency_leave']],
                    ['description' => 'Deduct if absent or MC', 'amount' => -50, 'predicate' => ['absence_codes' => ['unauthorized_absence', 'sick_leave']]],
                    ['description' => 'Deduct if EL', 'amount' => -50, 'predicate' => ['absence_codes' => ['emergency_leave']]],
                ],
                'effective_from' => '2026-01-01',
                'status' => 'active',
                'source_system' => 'hr2000',
                'source_label' => 'Conditional Allowance',
                'source_code' => 'FA',
                'metadata' => ['scenario' => 'attendance-dev'],
            ],
        );

        AttendanceAllowanceRule::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'night_shift_fw'],
            [
                'attendance_policy_group_id' => $policyGroup->id,
                'name' => 'Night Shift FW',
                'allowance_type' => AttendanceAllowanceRule::TYPE_DAILY,
                'ceiling_amount' => 31,
                'resolution_method' => AttendanceAllowanceRule::RESOLUTION_MIN,
                'condition_rows' => [
                    ['description' => 'Work on night shift, clock out after 8PM or before 8AM, min 4 hours', 'amount' => 1, 'predicate' => ['clock_out_after' => '20:00', 'clock_out_before' => '08:00', 'min_worked_minutes' => 240]],
                ],
                'source_script' => '(row.TimeOut >= 20:00 || row.TimeOut <= 08:00) && row.WorkHr >= 240',
                'effective_from' => '2026-01-01',
                'status' => 'active',
                'source_system' => 'hr2000',
                'source_label' => 'Conditional Allowance',
                'source_code' => 'NS-FW',
                'metadata' => ['scenario' => 'attendance-dev'],
            ],
        );
    }

    private function seedRosterPattern(Company $company, AttendanceShiftTemplate $dayShift, AttendanceShiftTemplate $nightShift): AttendanceRosterPattern
    {
        return AttendanceRosterPattern::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'prod_weekly_rotation'],
            [
                'name' => 'Production Weekly Rotation',
                'pattern_type' => AttendanceRosterPattern::TYPE_ROTATING,
                'pattern_definition' => [
                    'cycle_days' => 14,
                    'days' => [
                        ['offset' => 0, 'shift_code' => $dayShift->code],
                        ['offset' => 1, 'shift_code' => $dayShift->code],
                        ['offset' => 7, 'shift_code' => $nightShift->code],
                        ['offset' => 8, 'shift_code' => $nightShift->code],
                    ],
                ],
                'status' => AttendanceRosterPattern::STATUS_PUBLISHED,
                'source_system' => 'dev-seeder',
                'metadata' => ['scenario' => 'attendance-dev'],
            ],
        );
    }

    private function seedRosterAssignments(Company $company, $employees, AttendanceRosterPattern $pattern, AttendanceShiftTemplate $shift, AttendancePolicyGroup $policyGroup): void
    {
        foreach ($employees as $employee) {
            AttendanceRosterAssignment::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'employee_id' => $employee->id,
                    'effective_from' => '2026-05-01',
                ],
                [
                    'attendance_roster_pattern_id' => $pattern->id,
                    'attendance_shift_template_id' => $shift->id,
                    'attendance_policy_group_id' => $policyGroup->id,
                    'publish_state' => 'published',
                    'lock_state' => 'open',
                    'revision' => 1,
                    'exceptions' => [],
                    'metadata' => ['scenario' => 'attendance-dev'],
                ],
            );
        }
    }

    private function seedGeofences(Company $company): AttendanceGeofenceGroup
    {
        $fence = AttendanceGeofence::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'hq'],
            [
                'name' => 'Head Office',
                'location_label' => 'Main entrance',
                'latitude' => 3.1390000,
                'longitude' => 101.6869000,
                'radius_meters' => 150,
                'status' => 'active',
                'source_system' => 'hr2000',
                'source_label' => 'Geo Fence',
                'source_code' => 'HQ',
                'metadata' => ['scenario' => 'attendance-dev'],
            ],
        );

        $group = AttendanceGeofenceGroup::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'default_clock_locations'],
            [
                'name' => 'Default Clock Locations',
                'status' => 'active',
                'source_system' => 'hr2000',
                'source_label' => 'Geo Group',
                'source_code' => 'DEFAULT',
                'metadata' => ['scenario' => 'attendance-dev'],
            ],
        );

        $group->fences()->syncWithoutDetaching([$fence->id => ['sort_order' => 10, 'metadata' => json_encode(['scenario' => 'attendance-dev'])]]);

        return $group;
    }

    private function seedAttendanceDays(Company $company, $employees, AttendanceShiftTemplate $shift, AttendancePolicyGroup $policyGroup, AttendanceGeofenceGroup $geofenceGroup): void
    {
        $date = CarbonImmutable::parse('2026-05-13');

        foreach ($employees->values() as $index => $employee) {
            $day = AttendanceDay::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $date->toDateString())
                ->first();

            if (! $day instanceof AttendanceDay) {
                $day = new AttendanceDay([
                    'employee_id' => $employee->id,
                    'attendance_date' => $date->toDateString(),
                ]);
            }

            $exceptionTags = [];
            if ($index === 3) {
                $exceptionTags = ['missing_clock_events'];
            } elseif ($index === 1) {
                $exceptionTags = ['late_in'];
            }

            $day->fill([
                'company_id' => $company->id,
                'attendance_shift_template_id' => $shift->id,
                'attendance_policy_group_id' => $policyGroup->id,
                'status' => $index === 3 ? AttendanceDay::STATUS_EXCEPTION_PENDING : AttendanceDay::STATUS_READY_FOR_REVIEW,
                'day_type' => 'normal',
                'shift_starts_at' => $date->setTime(8, 0),
                'shift_ends_at' => $date->setTime(17, 0),
                'expected_minutes' => 480,
                'worked_minutes' => $index === 3 ? 0 : 535,
                'payable_minutes' => $index === 3 ? 0 : 480,
                'late_minutes' => $index === 1 ? 12 : 0,
                'absent_minutes' => $index === 3 ? 480 : 0,
                'overtime_candidate_minutes' => $index === 0 ? 55 : 0,
                'exception_tags' => $exceptionTags,
                'projection_snapshot' => ['source' => 'dev-seeder'],
                'metadata' => ['scenario' => 'attendance-dev'],
            ])->save();

            if ($index !== 3) {
                foreach ([['in', 7, 55 + $index], ['out', 17, 50]] as [$type, $hour, $minute]) {
                    AttendanceClockEvent::query()->updateOrCreate(
                        [
                            'employee_id' => $employee->id,
                            'event_type' => $type,
                            'occurred_at' => $date->setTime($hour, $minute),
                        ],
                        [
                            'company_id' => $company->id,
                            'attendance_day_id' => $day->id,
                            'attendance_geofence_group_id' => $geofenceGroup->id,
                            'source' => $index === 1 ? AttendanceClockEvent::SOURCE_WEB : AttendanceClockEvent::SOURCE_APP,
                            'outlet_label' => 'Main entrance',
                            'ip_address' => $index === 1 ? '121.121.22.10' : null,
                            'geofence_result' => 'inside',
                            'photo_evidence_present' => true,
                            'metadata' => ['scenario' => 'attendance-dev'],
                        ],
                    );
                }
            }

            if ($index === 0) {
                AttendanceOvertimeRequest::query()->updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'employee_id' => $employee->id,
                        'attendance_day_id' => $day->id,
                    ],
                    [
                        'request_mode' => 'post_work_actual',
                        'status' => AttendanceOvertimeRequest::STATUS_SUBMITTED,
                        'starts_at' => $date->setTime(17, 0),
                        'ends_at' => $date->setTime(18, 0),
                        'requested_minutes' => 60,
                        'approved_minutes' => 0,
                        'payable_minutes' => 0,
                        'reason' => 'Production line changeover',
                        'attachment_count' => 0,
                        'submitted_at' => $date->setTime(18, 10),
                        'policy_snapshot' => ['attendance_policy_group_id' => $policyGroup->id, 'version' => $policyGroup->version],
                        'metadata' => ['scenario' => 'attendance-dev'],
                    ],
                );
            }
        }
    }

    private function seedAbsenceBatch(Company $company, $employees): void
    {
        $batch = AttendanceAbsenceBatch::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'reference' => 'ABS-2026-05',
            ],
            [
                'status' => AttendanceAbsenceBatch::STATUS_GENERATED,
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-31',
                'lock_date' => '2025-12-31',
                'filters' => ['normal_day' => true, 'no_shift_no' => true, 'no_leave_type' => true],
                'generated_at' => '2026-05-13 09:00:00',
                'metadata' => ['scenario' => 'attendance-dev'],
            ],
        );

        $employee = $employees->values()->get(3);
        if (! $employee instanceof Employee) {
            return;
        }

        AttendanceAbsenceBatchEntry::query()->updateOrCreate(
            [
                'attendance_absence_batch_id' => $batch->id,
                'employee_id' => $employee->id,
                'absence_date' => '2026-05-13',
            ],
            [
                'day_type' => 'normal',
                'absence_code' => 'unauthorized_absence',
                'status' => 'candidate',
                'reason' => 'Generated from missing clock events',
                'metadata' => ['scenario' => 'attendance-dev'],
            ],
        );
    }
}
