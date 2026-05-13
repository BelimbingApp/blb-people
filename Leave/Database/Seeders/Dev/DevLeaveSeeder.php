<?php

namespace App\Modules\People\Leave\Database\Seeders\Dev;

use App\Base\Database\Seeders\DevSeeder;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Database\Seeders\Dev\DevEmployeeSeeder;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Leave\Models\LeaveAssignment;
use App\Modules\People\Leave\Models\LeaveBalanceLedgerEntry;
use App\Modules\People\Leave\Models\LeaveEntitlementPolicy;
use App\Modules\People\Leave\Models\LeaveEntitlementPolicyBand;
use App\Modules\People\Leave\Models\LeaveRequest;
use App\Modules\People\Leave\Models\LeaveRequestAuditEvent;
use App\Modules\People\Leave\Models\LeaveRequestDay;
use App\Modules\People\Leave\Models\LeaveRequestPolicy;
use App\Modules\People\Leave\Models\LeaveType;
use App\Modules\People\Settings\Database\Seeders\Dev\DevPeopleSettingsSeeder;
use DateTimeImmutable;

class DevLeaveSeeder extends DevSeeder
{
    protected array $dependencies = [
        DevEmployeeSeeder::class,
        DevPeopleSettingsSeeder::class,
    ];

    private const PACK_IDENTIFIER = 'belimbing/leave-dev';

    private const PACK_VERSION = '2026.dev';

    private const EFFECTIVE_FROM = '2026-01-01';

    private const LEAVE_YEAR = 2026;

    protected function seed(): void
    {
        $company = $this->licenseeCompany();

        if ($company === null) {
            return;
        }

        $types = $this->seedLeaveTypes($company);
        $entitlementPolicies = $this->seedEntitlementPolicies($company, $types);
        $requestPolicies = $this->seedRequestPolicies($company, $types);
        $assignments = $this->seedAssignments($company, $types, $entitlementPolicies, $requestPolicies);

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

        $this->seedOpeningBalances($company, $employees, $types);
        $this->seedSampleRequests($company, $employees, $types, $assignments);
    }

    /** @return array<string, LeaveType> */
    private function seedLeaveTypes(Company $company): array
    {
        $definitions = [
            ['code' => 'annual_leave', 'name' => 'Annual Leave', 'paid' => true, 'compulsory_attachment' => false, 'default_unit' => LeaveType::UNIT_DAY, 'interacts_with_payroll' => false],
            ['code' => 'sick_leave', 'name' => 'Sick Leave', 'paid' => true, 'compulsory_attachment' => true, 'default_unit' => LeaveType::UNIT_DAY, 'interacts_with_payroll' => false],
            ['code' => 'unpaid_leave', 'name' => 'Unpaid Leave', 'paid' => false, 'compulsory_attachment' => false, 'default_unit' => LeaveType::UNIT_DAY, 'interacts_with_payroll' => true, 'payroll_pay_item_code' => LeaveType::PAYROLL_CODE_UNPAID_LEAVE],
        ];

        $out = [];
        foreach ($definitions as $def) {
            $out[$def['code']] = LeaveType::query()->updateOrCreate(
                ['company_id' => $company->id, 'code' => $def['code']],
                [
                    'name' => $def['name'],
                    'paid' => $def['paid'],
                    'default_unit' => $def['default_unit'],
                    'default_approval_depth' => 1,
                    'interacts_with_payroll' => $def['interacts_with_payroll'],
                    'compulsory_attachment' => $def['compulsory_attachment'],
                    'payroll_pay_item_code' => $def['payroll_pay_item_code'] ?? null,
                    'status' => LeaveType::STATUS_ACTIVE,
                    'pack_identifier' => self::PACK_IDENTIFIER,
                    'pack_version' => self::PACK_VERSION,
                    'metadata' => ['scenario' => 'browser-demo'],
                ],
            );
        }

        return $out;
    }

    /**
     * @param  array<string, LeaveType>  $types
     * @return array<string, LeaveEntitlementPolicy>
     */
    private function seedEntitlementPolicies(Company $company, array $types): array
    {
        $configs = [
            'annual_leave' => [
                'code' => 'dev_annual_policy',
                'name' => 'Annual Leave Entitlement (Dev)',
                'bring_forward_cap_days' => 7,
                'bring_forward_expiry_month' => 3,
                'bands' => [[0, 2, 14], [2, 5, 16], [5, null, 21]],
            ],
            'sick_leave' => [
                'code' => 'dev_sick_policy',
                'name' => 'Sick Leave Entitlement (Dev)',
                'bring_forward_cap_days' => null,
                'bands' => [[0, 2, 14], [2, 5, 18], [5, null, 22]],
            ],
            'unpaid_leave' => [
                'code' => 'dev_unpaid_policy',
                'name' => 'Unpaid Leave Entitlement (Dev)',
                'bring_forward_cap_days' => null,
                'bands' => [[0, null, 0]],
            ],
        ];

        $out = [];
        foreach ($configs as $typeCode => $config) {
            $type = $types[$typeCode] ?? null;
            if ($type === null) {
                continue;
            }

            $policy = LeaveEntitlementPolicy::query()->updateOrCreate(
                ['company_id' => $company->id, 'code' => $config['code']],
                [
                    'leave_type_id' => $type->id,
                    'name' => $config['name'],
                    'accrual_method' => LeaveEntitlementPolicy::ACCRUAL_ANNUAL_LUMP_NO_PRORATE,
                    'entitlement_rounding' => LeaveEntitlementPolicy::ROUNDING_NONE,
                    'prorate_for_joiners' => true,
                    'prorate_for_leavers' => true,
                    'bring_forward_cap_days' => $config['bring_forward_cap_days'],
                    'bring_forward_expiry_month' => $config['bring_forward_expiry_month'] ?? null,
                    'bring_forward_anchor' => LeaveEntitlementPolicy::ANCHOR_YEAR_START,
                    'effective_from' => self::EFFECTIVE_FROM,
                    'version' => 1,
                    'status' => 'active',
                    'metadata' => ['scenario' => 'browser-demo'],
                ],
            );

            LeaveEntitlementPolicyBand::query()->where('leave_entitlement_policy_id', $policy->id)->delete();
            foreach ($config['bands'] as $idx => [$min, $max, $days]) {
                LeaveEntitlementPolicyBand::query()->create([
                    'leave_entitlement_policy_id' => $policy->id,
                    'min_years_of_service' => $min,
                    'max_years_of_service' => $max,
                    'entitlement_days' => $days,
                    'sort_order' => ($idx + 1) * 10,
                    'metadata' => ['scenario' => 'browser-demo'],
                ]);
            }

            $out[$typeCode] = $policy;
        }

        return $out;
    }

    /**
     * @param  array<string, LeaveType>  $types
     * @return array<string, LeaveRequestPolicy>
     */
    private function seedRequestPolicies(Company $company, array $types): array
    {
        $defaults = [
            'allow_negative_balance' => false,
            'include_pending_as_taken' => true,
            'allow_multiple_applications_per_day' => false,
            'no_cross_month_split' => false,
            'compulsory_attachment' => false,
            'exclude_holiday_from_count' => true,
            'exclude_off_day_from_count' => true,
            'exclude_rest_day_from_count' => true,
            'advance_notice' => ['standard_days' => 3, 'short_notice' => ['allowed' => true, 'tag' => 'EMERGENCY']],
            'back_date' => ['allowed' => true, 'max_days' => 14, 'tag' => 'LATE_SUBMISSION'],
            'effective_from' => self::EFFECTIVE_FROM,
            'version' => 1,
            'status' => 'active',
        ];

        $overrides = [
            'annual_leave' => ['code' => 'dev_annual_request', 'name' => 'Annual Leave Request Policy (Dev)'],
            'sick_leave' => ['code' => 'dev_sick_request', 'name' => 'Sick Leave Request Policy (Dev)', 'compulsory_attachment' => true, 'max_days_per_application' => 22],
            'unpaid_leave' => ['code' => 'dev_unpaid_request', 'name' => 'Unpaid Leave Request Policy (Dev)', 'allow_negative_balance' => true, 'no_cross_month_split' => true],
        ];

        $out = [];
        foreach ($overrides as $typeCode => $override) {
            $type = $types[$typeCode] ?? null;
            if ($type === null) {
                continue;
            }

            $attrs = array_merge($defaults, $override, [
                'company_id' => $company->id,
                'leave_type_id' => $type->id,
                'metadata' => ['scenario' => 'browser-demo'],
            ]);

            $out[$typeCode] = LeaveRequestPolicy::query()->updateOrCreate(
                ['company_id' => $company->id, 'code' => $attrs['code']],
                $attrs,
            );
        }

        return $out;
    }

    /**
     * @param  array<string, LeaveType>  $types
     * @param  array<string, LeaveEntitlementPolicy>  $entitlementPolicies
     * @param  array<string, LeaveRequestPolicy>  $requestPolicies
     * @return array<string, LeaveAssignment>
     */
    private function seedAssignments(Company $company, array $types, array $entitlementPolicies, array $requestPolicies): array
    {
        $out = [];
        foreach (['annual_leave', 'sick_leave', 'unpaid_leave'] as $code) {
            $type = $types[$code] ?? null;
            $entitlement = $entitlementPolicies[$code] ?? null;
            $request = $requestPolicies[$code] ?? null;
            if ($type === null || $entitlement === null || $request === null) {
                continue;
            }

            $out[$code] = LeaveAssignment::query()->updateOrCreate(
                ['company_id' => $company->id, 'code' => 'dev_default_'.$code],
                [
                    'name' => 'Default '.$type->name,
                    'leave_type_id' => $type->id,
                    'leave_entitlement_policy_id' => $entitlement->id,
                    'leave_request_policy_id' => $request->id,
                    'cohort_predicate' => [],
                    'effective_from' => self::EFFECTIVE_FROM,
                    'status' => 'active',
                    'metadata' => ['scenario' => 'browser-demo'],
                ],
            );
        }

        return $out;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Employee>  $employees
     * @param  array<string, LeaveType>  $types
     */
    private function seedOpeningBalances(Company $company, $employees, array $types): void
    {
        $openings = [
            'annual_leave' => 14.0,
            'sick_leave' => 22.0,
        ];

        foreach ($employees as $employee) {
            foreach ($openings as $typeCode => $days) {
                $type = $types[$typeCode] ?? null;
                if ($type === null) {
                    continue;
                }

                $exists = LeaveBalanceLedgerEntry::query()
                    ->where('company_id', $company->id)
                    ->where('employee_id', $employee->id)
                    ->where('leave_type_id', $type->id)
                    ->where('leave_year', self::LEAVE_YEAR)
                    ->where('entry_type', LeaveBalanceLedgerEntry::ENTRY_OPENING)
                    ->exists();

                if ($exists) {
                    continue;
                }

                LeaveBalanceLedgerEntry::query()->create([
                    'company_id' => $company->id,
                    'employee_id' => $employee->id,
                    'leave_type_id' => $type->id,
                    'leave_year' => self::LEAVE_YEAR,
                    'entry_type' => LeaveBalanceLedgerEntry::ENTRY_OPENING,
                    'quantity' => $days,
                    'unit' => 'day',
                    'source_type' => LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
                    'pack_identifier' => self::PACK_IDENTIFIER,
                    'pack_version' => self::PACK_VERSION,
                    'occurred_on' => self::EFFECTIVE_FROM,
                    'note' => 'Dev seeder opening balance',
                    'metadata' => ['scenario' => 'browser-demo'],
                ]);
            }
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Employee>  $employees
     * @param  array<string, LeaveType>  $types
     * @param  array<string, LeaveAssignment>  $assignments
     */
    private function seedSampleRequests(Company $company, $employees, array $types, array $assignments): void
    {
        $annualType = $types['annual_leave'] ?? null;
        $annualAssignment = $assignments['annual_leave'] ?? null;
        $sickType = $types['sick_leave'] ?? null;
        $sickAssignment = $assignments['sick_leave'] ?? null;

        if ($annualType === null || $annualAssignment === null) {
            return;
        }

        $scenarios = [
            // Submitted, awaiting approval — populates the Approvals queue
            [
                'employee_index' => 0,
                'type' => $annualType,
                'assignment' => $annualAssignment,
                'status' => LeaveRequest::STATUS_SUBMITTED,
                'starts_on' => '2026-06-10',
                'ends_on' => '2026-06-12',
                'quantity' => 3.0,
                'submitted_at' => '2026-05-25 09:00:00',
                'apply_taken_ledger' => false,
                'audit' => [['from' => 'draft', 'to' => 'submitted', 'at' => '2026-05-25 09:00:00']],
            ],
            // Submitted by a second employee — overlapping risk in team calendar
            [
                'employee_index' => 1,
                'type' => $annualType,
                'assignment' => $annualAssignment,
                'status' => LeaveRequest::STATUS_SUBMITTED,
                'starts_on' => '2026-06-11',
                'ends_on' => '2026-06-13',
                'quantity' => 3.0,
                'submitted_at' => '2026-05-26 14:00:00',
                'apply_taken_ledger' => false,
                'audit' => [['from' => 'draft', 'to' => 'submitted', 'at' => '2026-05-26 14:00:00']],
            ],
            // Approved + applied — populates My Balance "Taken" and team calendar
            [
                'employee_index' => 2,
                'type' => $annualType,
                'assignment' => $annualAssignment,
                'status' => LeaveRequest::STATUS_APPLIED,
                'starts_on' => '2026-04-20',
                'ends_on' => '2026-04-22',
                'quantity' => 3.0,
                'submitted_at' => '2026-04-05 10:00:00',
                'approved_at' => '2026-04-06 08:30:00',
                'applied_at' => '2026-04-06 08:31:00',
                'apply_taken_ledger' => true,
                'audit' => [
                    ['from' => 'draft', 'to' => 'submitted', 'at' => '2026-04-05 10:00:00'],
                    ['from' => 'submitted', 'to' => 'approved', 'at' => '2026-04-06 08:30:00'],
                    ['from' => 'approved', 'to' => 'applied', 'at' => '2026-04-06 08:31:00'],
                ],
            ],
        ];

        if ($sickType !== null && $sickAssignment !== null) {
            // Rejected sick leave — populates audit trail
            $scenarios[] = [
                'employee_index' => 3,
                'type' => $sickType,
                'assignment' => $sickAssignment,
                'status' => LeaveRequest::STATUS_REJECTED,
                'starts_on' => '2026-03-15',
                'ends_on' => '2026-03-15',
                'quantity' => 1.0,
                'submitted_at' => '2026-03-14 18:00:00',
                'rejected_at' => '2026-03-15 09:00:00',
                'rejection_reason' => 'Medical certificate not attached',
                'apply_taken_ledger' => false,
                'audit' => [
                    ['from' => 'draft', 'to' => 'submitted', 'at' => '2026-03-14 18:00:00'],
                    ['from' => 'submitted', 'to' => 'rejected', 'at' => '2026-03-15 09:00:00', 'reason' => 'Medical certificate not attached'],
                ],
            ];
        }

        foreach ($scenarios as $scenario) {
            $employee = $employees->values()->get($scenario['employee_index']);
            if ($employee === null) {
                continue;
            }

            $existing = LeaveRequest::query()
                ->where('company_id', $company->id)
                ->where('employee_id', $employee->id)
                ->where('leave_type_id', $scenario['type']->id)
                ->where('starts_on', $scenario['starts_on'])
                ->first();

            if ($existing !== null) {
                continue;
            }

            $request = LeaveRequest::query()->create([
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'leave_type_id' => $scenario['type']->id,
                'leave_assignment_id' => $scenario['assignment']->id,
                'leave_request_policy_id' => $scenario['assignment']->leave_request_policy_id,
                'leave_request_policy_version' => 1,
                'status' => $scenario['status'],
                'starts_on' => $scenario['starts_on'],
                'ends_on' => $scenario['ends_on'],
                'unit' => LeaveRequest::UNIT_DAY,
                'quantity' => $scenario['quantity'],
                'attachment_count' => 0,
                'short_notice' => false,
                'back_dated' => false,
                'submitted_at' => $scenario['submitted_at'] ?? null,
                'approved_at' => $scenario['approved_at'] ?? null,
                'rejected_at' => $scenario['rejected_at'] ?? null,
                'applied_at' => $scenario['applied_at'] ?? null,
                'rejection_reason' => $scenario['rejection_reason'] ?? null,
                'metadata' => ['scenario' => 'browser-demo'],
            ]);

            $period = new \DatePeriod(
                new DateTimeImmutable($scenario['starts_on']),
                new \DateInterval('P1D'),
                (new DateTimeImmutable($scenario['ends_on']))->modify('+1 day'),
            );

            foreach ($period as $day) {
                LeaveRequestDay::query()->create([
                    'leave_request_id' => $request->id,
                    'occurs_on' => $day->format('Y-m-d'),
                    'portion' => LeaveRequestDay::PORTION_FULL,
                    'hours_count' => null,
                    'daytype' => LeaveRequestDay::DAYTYPE_WORKING,
                    'counts_against_balance' => true,
                    'metadata' => ['scenario' => 'browser-demo'],
                ]);
            }

            foreach ($scenario['audit'] as $event) {
                LeaveRequestAuditEvent::query()->create([
                    'leave_request_id' => $request->id,
                    'from_status' => $event['from'],
                    'to_status' => $event['to'],
                    'reason' => $event['reason'] ?? null,
                    'occurred_at' => $event['at'],
                    'metadata' => ['scenario' => 'browser-demo'],
                ]);
            }

            if ($scenario['apply_taken_ledger']) {
                $ledgerEntry = LeaveBalanceLedgerEntry::query()->create([
                    'company_id' => $company->id,
                    'employee_id' => $employee->id,
                    'leave_type_id' => $scenario['type']->id,
                    'leave_year' => self::LEAVE_YEAR,
                    'entry_type' => LeaveBalanceLedgerEntry::ENTRY_TAKEN,
                    'quantity' => -1.0 * $scenario['quantity'],
                    'unit' => 'day',
                    'source_type' => LeaveBalanceLedgerEntry::SOURCE_LEAVE_REQUEST,
                    'source_id' => $request->id,
                    'pack_identifier' => self::PACK_IDENTIFIER,
                    'pack_version' => self::PACK_VERSION,
                    'occurred_on' => $scenario['starts_on'],
                    'note' => 'Dev seeder applied leave',
                    'metadata' => ['scenario' => 'browser-demo'],
                ]);

                $request->update(['applied_ledger_entry_id' => $ledgerEntry->id]);
            }
        }
    }
}
