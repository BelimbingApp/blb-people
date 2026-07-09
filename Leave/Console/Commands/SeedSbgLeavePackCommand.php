<?php

namespace App\Modules\People\Leave\Console\Commands;

use App\Modules\Core\Company\Models\Company;
use App\Modules\People\Leave\CountryPacks\Malaysia\MalaysiaStatutoryLeaveTypes;
use App\Modules\People\Leave\Models\LeaveAssignment;
use App\Modules\People\Leave\Models\LeaveEntitlementPolicy;
use App\Modules\People\Leave\Models\LeaveEntitlementPolicyBand;
use App\Modules\People\Leave\Models\LeaveRequestPolicy;
use App\Modules\People\Leave\Models\LeaveType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Seeds the SBG private-pack data derived from the HR2000 e-Leave reference
 * export (see docs/plans/people/sbg_leave_ref/). Non-statutory types (exam,
 * marriage, compassionate, time-slip, replacement variants, absent) live
 * here rather than in the Malaysia statutory pack. Annual-leave service
 * bands match SBG's actual configuration (AL-LOCAL 12/14/16/21; AL-FW 8/12/16).
 *
 * Idempotent: re-running upserts by `(company_id, code)` and does not
 * duplicate rows.
 */
#[AsCommand(name: 'blb:leave:seed-sbg-pack')]
class SeedSbgLeavePackCommand extends Command
{
    private const DEFAULT_EFFECTIVE_FROM = '2026-01-01';

    protected $description = 'Seed SBG private leave-pack data (non-statutory types, AL bands, FM/FW/MM/SINGLE cohorts)';

    protected $signature = 'blb:leave:seed-sbg-pack
                            {--company= : Target company ID (defaults to the first active company)}';

    public const PACK_IDENTIFIER = 'kiatng/blb-sbg-leave';

    public const PACK_VERSION = '2026.dev';

    public function handle(): int
    {
        $companyId = $this->option('company');
        $company = $companyId !== null
            ? Company::query()->findOrFail((int) $companyId)
            : Company::query()->orderBy('id')->first();

        if ($company === null) {
            $this->error('No companies found. Create a company before seeding the SBG leave pack.');

            return self::FAILURE;
        }

        $this->info(sprintf('Seeding SBG leave pack for company [%d] %s', $company->getKey(), $company->name ?? ''));

        DB::transaction(function () use ($company): void {
            $types = $this->seedLeaveTypes($company->getKey());
            $requestPolicies = $this->seedRequestPolicies($company->getKey(), $types);
            $entitlementPolicies = $this->seedEntitlementPolicies($company->getKey(), $types);
            $this->seedAssignments($company->getKey(), $types, $requestPolicies, $entitlementPolicies);
        });

        $this->info('SBG leave pack seeded.');

        return self::SUCCESS;
    }

    /** @return array<string, LeaveType> */
    private function seedLeaveTypes(int $companyId): array
    {
        $definitions = [
            ['code' => 'marriage_leave', 'name' => 'Marriage Leave', 'paid' => true, 'compulsory_attachment' => true, 'default_unit' => LeaveType::UNIT_DAY],
            ['code' => 'compassionate_leave', 'name' => 'Compassionate Leave', 'paid' => true, 'compulsory_attachment' => true, 'default_unit' => LeaveType::UNIT_DAY],
            ['code' => 'exam_leave', 'name' => 'Exam Leave', 'paid' => true, 'compulsory_attachment' => true, 'default_unit' => LeaveType::UNIT_DAY],
            ['code' => 'time_slip', 'name' => 'Time Slip (2 hour)', 'paid' => true, 'default_unit' => LeaveType::UNIT_HOUR, 'hour_quantum_minutes' => 120],
            ['code' => 'replacement_leave', 'name' => 'Replacement Leave', 'paid' => true, 'default_unit' => LeaveType::UNIT_DAY],
            ['code' => 'replacement_leave_alt', 'name' => 'Replacement Leave (Alt Workflow)', 'paid' => true, 'default_unit' => LeaveType::UNIT_DAY],
        ];

        $out = [];
        foreach ($definitions as $def) {
            $type = LeaveType::query()->updateOrCreate(
                ['company_id' => $companyId, 'code' => $def['code']],
                [
                    'name' => $def['name'],
                    'paid' => $def['paid'],
                    'default_unit' => $def['default_unit'],
                    'hour_quantum_minutes' => $def['hour_quantum_minutes'] ?? null,
                    'default_approval_depth' => 1,
                    'interacts_with_payroll' => false,
                    'compulsory_attachment' => $def['compulsory_attachment'] ?? false,
                    'status' => LeaveType::STATUS_ACTIVE,
                    'pack_identifier' => self::PACK_IDENTIFIER,
                    'pack_version' => self::PACK_VERSION,
                ],
            );
            $out[$def['code']] = $type;
        }

        foreach ([
            MalaysiaStatutoryLeaveTypes::CODE_ANNUAL,
            MalaysiaStatutoryLeaveTypes::CODE_SICK,
            MalaysiaStatutoryLeaveTypes::CODE_HOSPITALIZATION,
            MalaysiaStatutoryLeaveTypes::CODE_MATERNITY,
            MalaysiaStatutoryLeaveTypes::CODE_PATERNITY,
            MalaysiaStatutoryLeaveTypes::CODE_UNPAID,
            MalaysiaStatutoryLeaveTypes::CODE_UNAUTHORIZED_ABSENCE,
        ] as $statutoryCode) {
            $interactsWithPayroll = in_array($statutoryCode, [MalaysiaStatutoryLeaveTypes::CODE_UNPAID, MalaysiaStatutoryLeaveTypes::CODE_UNAUTHORIZED_ABSENCE], true);

            $out[$statutoryCode] = LeaveType::query()->firstOrCreate(
                ['company_id' => $companyId, 'code' => $statutoryCode],
                [
                    'name' => ucwords(str_replace('_', ' ', $statutoryCode)),
                    'paid' => ! in_array($statutoryCode, [MalaysiaStatutoryLeaveTypes::CODE_UNPAID, MalaysiaStatutoryLeaveTypes::CODE_UNAUTHORIZED_ABSENCE], true),
                    'default_unit' => LeaveType::UNIT_DAY,
                    'default_approval_depth' => 1,
                    'interacts_with_payroll' => $interactsWithPayroll,
                    'audit_tag' => $statutoryCode === MalaysiaStatutoryLeaveTypes::CODE_UNAUTHORIZED_ABSENCE ? 'discipline' : null,
                    'compulsory_attachment' => in_array($statutoryCode, [
                        MalaysiaStatutoryLeaveTypes::CODE_SICK,
                        MalaysiaStatutoryLeaveTypes::CODE_HOSPITALIZATION,
                        MalaysiaStatutoryLeaveTypes::CODE_MATERNITY,
                        MalaysiaStatutoryLeaveTypes::CODE_PATERNITY,
                    ], true),
                    'status' => LeaveType::STATUS_ACTIVE,
                    'pack_identifier' => 'belimbing/leave-my',
                ],
            );

            if ($interactsWithPayroll && Schema::hasTable('people_payroll_leave_type_pay_items')) {
                DB::table('people_payroll_leave_type_pay_items')->updateOrInsert(
                    ['leave_type_id' => $out[$statutoryCode]->id, 'effective_from' => '2026-01-01'],
                    [
                        'company_id' => $companyId,
                        'payroll_pay_item_code' => LeaveType::PAYROLL_CODE_UNPAID_LEAVE,
                        'effective_to' => null,
                        'metadata' => json_encode(['scenario' => 'sbg-leave-pack']),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }

        return $out;
    }

    /**
     * @param  array<string, LeaveType>  $types
     * @return array<string, LeaveRequestPolicy>
     */
    private function seedRequestPolicies(int $companyId, array $types): array
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
            'day_of_week_unit_overrides' => null,
            'max_days_per_application' => null,
            'advance_notice' => ['standard_days' => 3],
            'back_date' => ['allowed' => false],
            'replacement_expiry' => null,
            'effective_from' => self::DEFAULT_EFFECTIVE_FROM,
            'version' => 1,
            'status' => 'active',
        ];

        $perType = [
            MalaysiaStatutoryLeaveTypes::CODE_ANNUAL => [
                'day_of_week_unit_overrides' => ['sat' => 'half_day'],
                'max_days_per_application' => null,
                'advance_notice' => ['standard_days' => 3, 'short_notice' => ['allowed' => true, 'tag' => 'EMERGENCY']],
                'back_date' => ['allowed' => true, 'max_days' => 30, 'tag' => 'LATE_SUBMISSION'],
            ],
            MalaysiaStatutoryLeaveTypes::CODE_SICK => ['compulsory_attachment' => true, 'max_days_per_application' => 22, 'back_date' => ['allowed' => true, 'max_days' => 7, 'tag' => 'LATE_SUBMISSION']],
            MalaysiaStatutoryLeaveTypes::CODE_HOSPITALIZATION => ['compulsory_attachment' => true, 'max_days_per_application' => 60, 'advance_notice' => ['standard_days' => 14]],
            MalaysiaStatutoryLeaveTypes::CODE_MATERNITY => ['compulsory_attachment' => true, 'max_days_per_application' => 98, 'advance_notice' => ['standard_days' => 14]],
            MalaysiaStatutoryLeaveTypes::CODE_PATERNITY => ['compulsory_attachment' => true, 'max_days_per_application' => 7, 'advance_notice' => ['standard_days' => 7]],
            MalaysiaStatutoryLeaveTypes::CODE_UNPAID => [
                'allow_negative_balance' => true,
                'no_cross_month_split' => true,
                'max_days_per_application' => 31,
                'day_of_week_unit_overrides' => ['sat' => 'half_day'],
            ],
            MalaysiaStatutoryLeaveTypes::CODE_UNAUTHORIZED_ABSENCE => ['allow_negative_balance' => true, 'no_cross_month_split' => true, 'max_days_per_application' => 99],
            'marriage_leave' => ['compulsory_attachment' => true, 'max_days_per_application' => 3, 'advance_notice' => ['standard_days' => 7]],
            'compassionate_leave' => ['compulsory_attachment' => true, 'max_days_per_application' => 3, 'back_date' => ['allowed' => true, 'max_days' => 14, 'tag' => 'LATE_SUBMISSION']],
            'exam_leave' => ['compulsory_attachment' => true, 'max_days_per_application' => 4, 'advance_notice' => ['standard_days' => 7]],
            'time_slip' => ['allow_negative_balance' => true, 'allow_multiple_applications_per_day' => true, 'max_days_per_application' => 31],
            'replacement_leave' => ['advance_notice' => ['standard_days' => 3, 'short_notice' => ['allowed' => true, 'tag' => 'EMERGENCY']]],
            'replacement_leave_alt' => ['advance_notice' => ['standard_days' => 3, 'short_notice' => ['allowed' => true, 'tag' => 'EMERGENCY']]],
        ];

        $out = [];
        foreach ($perType as $code => $overrides) {
            $type = $types[$code] ?? null;
            if ($type === null) {
                continue;
            }

            $attrs = array_merge($defaults, $overrides);
            $attrs['company_id'] = $companyId;
            $attrs['leave_type_id'] = $type->getKey();
            $attrs['code'] = 'sbg_'.$code.'_policy';
            $attrs['name'] = $type->name.' Policy (SBG)';

            $out[$code] = LeaveRequestPolicy::query()->updateOrCreate(
                ['company_id' => $companyId, 'code' => $attrs['code']],
                $attrs,
            );
        }

        return $out;
    }

    /**
     * @param  array<string, LeaveType>  $types
     * @return array<string, LeaveEntitlementPolicy>
     */
    private function seedEntitlementPolicies(int $companyId, array $types): array
    {
        $configs = [
            'al_local' => [
                'leave_type' => MalaysiaStatutoryLeaveTypes::CODE_ANNUAL,
                'code' => 'sbg_al_local',
                'name' => 'Annual Leave (Local) — SBG',
                'eligibility_predicate' => ['citizenship_status' => 'local'],
                'bring_forward_cap_days' => 7,
                'bring_forward_expiry_month' => 3,
                'bring_forward_anchor' => LeaveEntitlementPolicy::ANCHOR_YEAR_START,
                'bands' => [
                    [1, 2, 12],
                    [2, 4, 14],
                    [4, 5, 16],
                    [5, null, 21],
                ],
            ],
            'al_fw' => [
                'leave_type' => MalaysiaStatutoryLeaveTypes::CODE_ANNUAL,
                'code' => 'sbg_al_fw',
                'name' => 'Annual Leave (Foreign Worker) — SBG',
                'eligibility_predicate' => ['citizenship_status' => 'foreign'],
                'bring_forward_cap_days' => null,
                'bands' => [
                    [2, 5, 8],
                    [5, null, 12],
                ],
            ],
            'mc' => [
                'leave_type' => MalaysiaStatutoryLeaveTypes::CODE_SICK,
                'code' => 'sbg_mc',
                'name' => 'Medical Leave — SBG',
                'bands' => [
                    [2, 5, 14],
                    [5, null, 22],
                ],
            ],
            'hl' => [
                'leave_type' => MalaysiaStatutoryLeaveTypes::CODE_HOSPITALIZATION,
                'code' => 'sbg_hl',
                'name' => 'Hospitalization Leave — SBG',
                'bands' => [[0, null, 60]],
            ],
            'mtl' => [
                'leave_type' => MalaysiaStatutoryLeaveTypes::CODE_MATERNITY,
                'code' => 'sbg_mtl',
                'name' => 'Maternity Leave — SBG',
                'eligibility_predicate' => ['gender' => 'female'],
                'bands' => [[0, null, 98]],
            ],
            'ptl' => [
                'leave_type' => MalaysiaStatutoryLeaveTypes::CODE_PATERNITY,
                'code' => 'sbg_ptl',
                'name' => 'Paternity Leave — SBG',
                'eligibility_predicate' => ['gender' => 'male', 'marital_status' => 'married'],
                'bands' => [[0, null, 7]],
            ],
            'mrl' => [
                'leave_type' => 'marriage_leave',
                'code' => 'sbg_mrl',
                'name' => 'Marriage Leave — SBG',
                'eligibility_predicate' => ['marital_status' => 'single'],
                'bands' => [[0, null, 3]],
            ],
            'cl' => [
                'leave_type' => 'compassionate_leave',
                'code' => 'sbg_cl',
                'name' => 'Compassionate Leave — SBG',
                'bands' => [[0, null, 3]],
            ],
            'exam' => [
                'leave_type' => 'exam_leave',
                'code' => 'sbg_exam',
                'name' => 'Exam Leave — SBG',
                'bands' => [[0, null, 4]],
            ],
        ];

        $out = [];
        foreach ($configs as $key => $config) {
            $type = $types[$config['leave_type']] ?? null;
            if ($type === null) {
                continue;
            }

            $policy = LeaveEntitlementPolicy::query()->updateOrCreate(
                ['company_id' => $companyId, 'code' => $config['code']],
                [
                    'leave_type_id' => $type->getKey(),
                    'name' => $config['name'],
                    'accrual_method' => LeaveEntitlementPolicy::ACCRUAL_ANNUAL_LUMP_NO_PRORATE,
                    'entitlement_rounding' => LeaveEntitlementPolicy::ROUNDING_NONE,
                    'prorate_for_joiners' => true,
                    'prorate_for_leavers' => true,
                    'bring_forward_cap_days' => $config['bring_forward_cap_days'] ?? null,
                    'bring_forward_expiry_month' => $config['bring_forward_expiry_month'] ?? null,
                    'bring_forward_anchor' => $config['bring_forward_anchor'] ?? null,
                    'eligibility_predicate' => $config['eligibility_predicate'] ?? null,
                    'effective_from' => self::DEFAULT_EFFECTIVE_FROM,
                    'statutory_floor_pack_identifier' => 'belimbing/leave-my',
                    'statutory_floor_pack_version' => '2026.dev',
                    'version' => 1,
                    'status' => 'active',
                ],
            );

            LeaveEntitlementPolicyBand::query()->where('leave_entitlement_policy_id', $policy->getKey())->delete();
            foreach ($config['bands'] as $idx => [$min, $max, $days]) {
                LeaveEntitlementPolicyBand::query()->create([
                    'leave_entitlement_policy_id' => $policy->getKey(),
                    'min_years_of_service' => $min,
                    'max_years_of_service' => $max,
                    'entitlement_days' => $days,
                    'sort_order' => $idx * 10,
                ]);
            }

            $out[$key] = $policy;
        }

        return $out;
    }

    /**
     * @param  array<string, LeaveType>  $types
     * @param  array<string, LeaveRequestPolicy>  $requestPolicies
     * @param  array<string, LeaveEntitlementPolicy>  $entitlementPolicies
     */
    private function seedAssignments(int $companyId, array $types, array $requestPolicies, array $entitlementPolicies): void
    {
        $cohorts = [
            'FM' => ['gender' => 'female', 'marital_status' => 'married', 'citizenship_status' => 'local'],
            'FW' => ['citizenship_status' => 'foreign'],
            'MM' => ['gender' => 'male', 'marital_status' => 'married', 'citizenship_status' => 'local'],
            'SINGLE' => ['marital_status' => 'single', 'citizenship_status' => 'local'],
        ];

        $cohortLeaves = [
            'FM' => [
                [MalaysiaStatutoryLeaveTypes::CODE_ANNUAL, 'al_local'],
                [MalaysiaStatutoryLeaveTypes::CODE_MATERNITY, 'mtl'],
                [MalaysiaStatutoryLeaveTypes::CODE_HOSPITALIZATION, 'hl'],
                ['exam_leave', 'exam'],
                ['time_slip', null],
                ['replacement_leave_alt', null],
                [MalaysiaStatutoryLeaveTypes::CODE_UNPAID, null],
                [MalaysiaStatutoryLeaveTypes::CODE_SICK, 'mc'],
                ['compassionate_leave', 'cl'],
            ],
            'FW' => [
                [MalaysiaStatutoryLeaveTypes::CODE_ANNUAL, 'al_fw'],
                [MalaysiaStatutoryLeaveTypes::CODE_SICK, 'mc'],
                [MalaysiaStatutoryLeaveTypes::CODE_HOSPITALIZATION, 'hl'],
                [MalaysiaStatutoryLeaveTypes::CODE_UNPAID, null],
                [MalaysiaStatutoryLeaveTypes::CODE_UNAUTHORIZED_ABSENCE, null],
            ],
            'MM' => [
                [MalaysiaStatutoryLeaveTypes::CODE_HOSPITALIZATION, 'hl'],
                ['time_slip', null],
                [MalaysiaStatutoryLeaveTypes::CODE_PATERNITY, 'ptl'],
                ['exam_leave', 'exam'],
                [MalaysiaStatutoryLeaveTypes::CODE_SICK, 'mc'],
                ['replacement_leave_alt', null],
                [MalaysiaStatutoryLeaveTypes::CODE_UNPAID, null],
                [MalaysiaStatutoryLeaveTypes::CODE_ANNUAL, 'al_local'],
                ['compassionate_leave', 'cl'],
            ],
            'SINGLE' => [
                ['marriage_leave', 'mrl'],
                [MalaysiaStatutoryLeaveTypes::CODE_ANNUAL, 'al_local'],
                [MalaysiaStatutoryLeaveTypes::CODE_SICK, 'mc'],
                [MalaysiaStatutoryLeaveTypes::CODE_UNPAID, null],
                ['replacement_leave_alt', null],
                ['compassionate_leave', 'cl'],
                ['exam_leave', 'exam'],
                [MalaysiaStatutoryLeaveTypes::CODE_HOSPITALIZATION, 'hl'],
                ['time_slip', null],
            ],
        ];

        foreach ($cohortLeaves as $cohort => $leaves) {
            foreach ($leaves as [$typeCode, $entitlementKey]) {
                $type = $types[$typeCode] ?? null;
                $requestPolicy = $requestPolicies[$typeCode] ?? null;
                if ($type === null || $requestPolicy === null) {
                    continue;
                }
                $entitlement = $entitlementKey !== null ? ($entitlementPolicies[$entitlementKey] ?? null) : null;
                if ($entitlement === null) {
                    continue;
                }

                LeaveAssignment::query()->updateOrCreate(
                    ['company_id' => $companyId, 'code' => 'sbg_'.strtolower($cohort).'_'.$typeCode],
                    [
                        'name' => $cohort.' / '.$type->name,
                        'leave_type_id' => $type->getKey(),
                        'leave_entitlement_policy_id' => $entitlement->getKey(),
                        'leave_request_policy_id' => $requestPolicy->getKey(),
                        'cohort_predicate' => $cohorts[$cohort],
                        'effective_from' => self::DEFAULT_EFFECTIVE_FROM,
                        'status' => 'active',
                    ],
                );
            }
        }
    }
}
