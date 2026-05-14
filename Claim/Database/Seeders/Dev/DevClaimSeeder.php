<?php

namespace App\Modules\People\Claim\Database\Seeders\Dev;

use App\Base\Database\Seeders\DevSeeder;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Database\Seeders\Dev\DevEmployeeSeeder;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Claim\Models\ClaimAssignment;
use App\Modules\People\Claim\Models\ClaimAssignmentLine;
use App\Modules\People\Claim\Models\ClaimCategory;
use App\Modules\People\Claim\Models\ClaimContext;
use App\Modules\People\Claim\Models\ClaimLine;
use App\Modules\People\Claim\Models\ClaimPolicy;
use App\Modules\People\Claim\Models\ClaimPolicyBand;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Models\ClaimRequestAuditEvent;
use App\Modules\People\Claim\Models\ClaimType;
use App\Modules\People\Settings\Database\Seeders\Dev\DevPeopleSettingsSeeder;
use Illuminate\Database\Eloquent\Collection;

class DevClaimSeeder extends DevSeeder
{
    protected array $dependencies = [
        DevEmployeeSeeder::class,
        DevPeopleSettingsSeeder::class,
    ];

    private const PACK_IDENTIFIER = 'belimbing/claim-dev';

    private const PACK_VERSION = '2026.dev';

    private const EFFECTIVE_FROM = '2026-01-01';

    private const CLAIM_YEAR = 2026;

    protected function seed(): void
    {
        $company = $this->licenseeCompany();

        if (! $company instanceof Company) {
            return;
        }

        $categories = $this->seedCategories($company);
        $types = $this->seedTypes($company, $categories);
        $policies = $this->seedPolicies($company, $types);
        $contexts = $this->seedContexts($company);
        $assignment = $this->seedAssignment($company, $types, $policies);

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

        $this->seedSampleRequests($company, $employees, $types, $policies, $assignment, $contexts);
    }

    /** @return array<string, ClaimCategory> */
    private function seedCategories(Company $company): array
    {
        $definitions = [
            ['code' => 'MEDICAL', 'name' => 'Medical'],
            ['code' => 'TRAVEL', 'name' => 'Travel & Transport'],
            ['code' => 'MEAL', 'name' => 'Meal & Entertainment'],
        ];

        $out = [];
        foreach ($definitions as $def) {
            $out[$def['code']] = ClaimCategory::query()->updateOrCreate(
                ['company_id' => $company->id, 'code' => $def['code']],
                [
                    'name' => $def['name'],
                    'status' => ClaimCategory::STATUS_ACTIVE,
                    'source_system' => self::PACK_IDENTIFIER,
                    'metadata' => ['scenario' => 'browser-demo'],
                ],
            );
        }

        return $out;
    }

    /**
     * @param  array<string, ClaimCategory>  $categories
     * @return array<string, ClaimType>
     */
    private function seedTypes(Company $company, array $categories): array
    {
        $definitions = [
            [
                'code' => 'medical_gp', 'name' => 'Medical - GP Visit', 'category' => 'MEDICAL',
                'receipt_requirement' => ClaimType::RECEIPT_ALWAYS, 'provider_required' => true,
                'default_unit' => ClaimType::UNIT_AMOUNT, 'payroll_pay_item_code' => 'REIMB_MEDICAL',
                'taxability_hint' => 'exempt_medical', 'sort_order' => 10,
            ],
            [
                'code' => 'mileage', 'name' => 'Mileage', 'category' => 'TRAVEL',
                'receipt_requirement' => ClaimType::RECEIPT_NEVER, 'provider_required' => false,
                'default_unit' => ClaimType::UNIT_DISTANCE, 'calculation_mode' => 'rate_times_quantity',
                'payroll_pay_item_code' => 'REIMB_MILEAGE', 'sort_order' => 20,
            ],
            [
                'code' => 'meal_overtime', 'name' => 'Meal - Overtime', 'category' => 'MEAL',
                'receipt_requirement' => ClaimType::RECEIPT_ABOVE_AMOUNT, 'provider_required' => false,
                'default_unit' => ClaimType::UNIT_AMOUNT, 'payroll_pay_item_code' => 'REIMB_MEAL',
                'sort_order' => 30,
            ],
        ];

        $out = [];
        foreach ($definitions as $def) {
            $category = $categories[$def['category']] ?? null;

            $out[$def['code']] = ClaimType::query()->updateOrCreate(
                ['company_id' => $company->id, 'code' => $def['code']],
                [
                    'claim_category_id' => $category?->id,
                    'name' => $def['name'],
                    'default_unit' => $def['default_unit'],
                    'calculation_mode' => $def['calculation_mode'] ?? 'manual_amount',
                    'receipt_requirement' => $def['receipt_requirement'],
                    'provider_required' => $def['provider_required'],
                    'payroll_eligible' => true,
                    'payroll_pay_item_code' => $def['payroll_pay_item_code'],
                    'taxability_hint' => $def['taxability_hint'] ?? null,
                    'sort_order' => $def['sort_order'],
                    'allow_employee_submission' => true,
                    'allow_on_behalf_submission' => true,
                    'admin_only' => false,
                    'advance_settlement_allowed' => false,
                    'status' => ClaimType::STATUS_ACTIVE,
                    'source_system' => self::PACK_IDENTIFIER,
                    'metadata' => ['scenario' => 'browser-demo'],
                ],
            );
        }

        return $out;
    }

    /**
     * @param  array<string, ClaimType>  $types
     * @return array<string, ClaimPolicy>
     */
    private function seedPolicies(Company $company, array $types): array
    {
        $configs = [
            'medical_gp' => [
                'code' => 'dev_medical_gp_policy',
                'name' => 'Medical GP Entitlement (Dev)',
                'item_mode' => ClaimPolicy::MODE_SINGLE_VALUE,
                'receipt_rules' => ['required' => true, 'threshold_amount' => 0],
                'provider_rules' => ['required' => true],
                'bands' => [
                    [
                        'logical_operator' => '<=', 'threshold_value' => null,
                        'rate' => 1, 'per_claim_limit' => 150.00,
                        'per_month_limit' => 500.00, 'per_year_limit' => 2000.00,
                    ],
                ],
            ],
            'mileage' => [
                'code' => 'dev_mileage_policy',
                'name' => 'Mileage Entitlement (Dev)',
                'item_mode' => ClaimPolicy::MODE_RANGE,
                'auto_calculated' => true,
                'rate_type' => 'per_km',
                'bands' => [
                    [
                        'logical_operator' => '<=', 'threshold_value' => 100,
                        'rate' => 0.80, 'per_day_unit_limit' => 100.00,
                        'per_month_limit' => 800.00, 'per_year_limit' => 6000.00,
                    ],
                    [
                        'logical_operator' => '<=', 'threshold_value' => null,
                        'rate' => 0.60, 'per_day_unit_limit' => 200.00,
                        'per_month_limit' => 1500.00, 'per_year_limit' => 12000.00,
                    ],
                ],
            ],
            'meal_overtime' => [
                'code' => 'dev_meal_ot_policy',
                'name' => 'Meal Overtime Entitlement (Dev)',
                'item_mode' => ClaimPolicy::MODE_SINGLE_VALUE,
                'receipt_rules' => ['required_above' => 30],
                'bands' => [
                    [
                        'logical_operator' => '<=', 'threshold_value' => null,
                        'rate' => 1, 'per_claim_limit' => 50.00,
                        'per_month_limit' => 300.00, 'per_year_limit' => 2400.00,
                    ],
                ],
            ],
        ];

        $out = [];
        foreach ($configs as $typeCode => $config) {
            if (! isset($types[$typeCode])) {
                continue;
            }

            $policy = ClaimPolicy::query()->updateOrCreate(
                ['company_id' => $company->id, 'code' => $config['code']],
                [
                    'name' => $config['name'],
                    'item_mode' => $config['item_mode'],
                    'auto_calculated' => $config['auto_calculated'] ?? false,
                    'rate_type' => $config['rate_type'] ?? null,
                    'receipt_rules' => $config['receipt_rules'] ?? null,
                    'provider_rules' => $config['provider_rules'] ?? null,
                    'currency_rules' => null,
                    'advance_rules' => null,
                    'encumber_pending' => true,
                    'effective_from' => self::EFFECTIVE_FROM,
                    'version' => 1,
                    'status' => 'active',
                    'source_system' => self::PACK_IDENTIFIER,
                    'metadata' => ['scenario' => 'browser-demo'],
                ],
            );

            ClaimPolicyBand::query()->where('claim_policy_id', $policy->id)->delete();
            foreach ($config['bands'] as $idx => $band) {
                ClaimPolicyBand::query()->create(array_merge(
                    $band,
                    [
                        'claim_policy_id' => $policy->id,
                        'sort_order' => ($idx + 1) * 10,
                        'metadata' => ['scenario' => 'browser-demo'],
                    ],
                ));
            }

            $out[$typeCode] = $policy;
        }

        return $out;
    }

    /** @return array<string, ClaimContext> */
    private function seedContexts(Company $company): array
    {
        $definitions = [
            ['code' => 'INTERNAL', 'label' => 'Internal / General', 'max_claim_limit' => null],
            ['code' => 'CLIENT_ACME', 'label' => 'Client - ACME Sdn Bhd', 'max_claim_limit' => 5000.00],
        ];

        $out = [];
        foreach ($definitions as $def) {
            $out[$def['code']] = ClaimContext::query()->updateOrCreate(
                ['company_id' => $company->id, 'code' => $def['code']],
                [
                    'label' => $def['label'],
                    'max_claim_limit' => $def['max_claim_limit'],
                    'status' => ClaimContext::STATUS_ACTIVE,
                    'source_system' => self::PACK_IDENTIFIER,
                    'metadata' => ['scenario' => 'browser-demo'],
                ],
            );
        }

        return $out;
    }

    /**
     * @param  array<string, ClaimType>  $types
     * @param  array<string, ClaimPolicy>  $policies
     */
    private function seedAssignment(Company $company, array $types, array $policies): ClaimAssignment
    {
        $assignment = ClaimAssignment::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'dev_default_assignment'],
            [
                'name' => 'Default Claim Assignment',
                'cohort_predicate' => [],
                'effective_from' => self::EFFECTIVE_FROM,
                'status' => 'active',
                'source_system' => self::PACK_IDENTIFIER,
                'metadata' => ['scenario' => 'browser-demo'],
            ],
        );

        $sort = 10;
        foreach (['medical_gp', 'mileage', 'meal_overtime'] as $code) {
            $type = $types[$code] ?? null;
            $policy = $policies[$code] ?? null;
            if ($type === null || $policy === null) {
                continue;
            }

            ClaimAssignmentLine::query()->updateOrCreate(
                ['claim_assignment_id' => $assignment->id, 'claim_type_id' => $type->id],
                [
                    'claim_policy_id' => $policy->id,
                    'combine_tag' => null,
                    'uses_combined_cap' => false,
                    'hidden_from_application' => false,
                    'sort_order' => $sort,
                    'status' => ClaimAssignmentLine::STATUS_ACTIVE,
                    'metadata' => ['scenario' => 'browser-demo'],
                ],
            );
            $sort += 10;
        }

        return $assignment;
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  array<string, ClaimType>  $types
     * @param  array<string, ClaimPolicy>  $policies
     * @param  array<string, ClaimContext>  $contexts
     */
    private function seedSampleRequests(
        Company $company,
        $employees,
        array $types,
        array $policies,
        ClaimAssignment $assignment,
        array $contexts,
    ): void {
        $assignmentLinesByType = ClaimAssignmentLine::query()
            ->where('claim_assignment_id', $assignment->id)
            ->get()
            ->keyBy('claim_type_id');

        $internal = $contexts['INTERNAL'] ?? null;

        $scenarios = [
            // Submitted medical claim - populates approval queue
            [
                'employee_index' => 0,
                'reference' => 'CLM-2026-00001',
                'status' => ClaimRequest::STATUS_SUBMITTED,
                'submitted_at' => '2026-05-02 09:00:00',
                'context' => $internal,
                'lines' => [
                    [
                        'type' => 'medical_gp', 'incurred_on' => '2026-04-28',
                        'description' => 'GP consultation - Klinik Sentosa',
                        'requested_amount' => 85.00, 'provider_name' => 'Klinik Sentosa',
                        'receipt_number' => 'KS-2026-0428-01', 'attachment_count' => 1,
                    ],
                ],
                'audit' => [['from' => 'draft', 'to' => 'submitted', 'at' => '2026-05-02 09:00:00']],
            ],
            // Mileage claim approved
            [
                'employee_index' => 1,
                'reference' => 'CLM-2026-00002',
                'status' => ClaimRequest::STATUS_APPROVED,
                'submitted_at' => '2026-04-10 14:00:00',
                'approved_at' => '2026-04-11 10:00:00',
                'context' => $internal,
                'lines' => [
                    [
                        'type' => 'mileage', 'incurred_on' => '2026-04-09',
                        'description' => 'Client visit - KL to Shah Alam',
                        'unit' => ClaimType::UNIT_DISTANCE,
                        'quantity' => 45, 'rate' => 0.80,
                        'requested_amount' => 36.00, 'approved_amount' => 36.00,
                        'attachment_count' => 0,
                    ],
                ],
                'audit' => [
                    ['from' => 'draft', 'to' => 'submitted', 'at' => '2026-04-10 14:00:00'],
                    ['from' => 'submitted', 'to' => 'approved', 'at' => '2026-04-11 10:00:00'],
                ],
            ],
            // Meal claim rejected
            [
                'employee_index' => 2,
                'reference' => 'CLM-2026-00003',
                'status' => ClaimRequest::STATUS_REJECTED,
                'submitted_at' => '2026-03-20 18:00:00',
                'rejected_at' => '2026-03-21 09:00:00',
                'decision_reason' => 'Receipt not attached for amount above threshold',
                'context' => $internal,
                'lines' => [
                    [
                        'type' => 'meal_overtime', 'incurred_on' => '2026-03-19',
                        'description' => 'OT dinner',
                        'requested_amount' => 45.00,
                        'attachment_count' => 0,
                    ],
                ],
                'audit' => [
                    ['from' => 'draft', 'to' => 'submitted', 'at' => '2026-03-20 18:00:00'],
                    [
                        'from' => 'submitted', 'to' => 'rejected',
                        'at' => '2026-03-21 09:00:00',
                        'reason' => 'Receipt not attached for amount above threshold',
                    ],
                ],
            ],
            // Draft claim - employee still editing
            [
                'employee_index' => 3,
                'reference' => null,
                'status' => ClaimRequest::STATUS_DRAFT,
                'context' => $internal,
                'lines' => [
                    [
                        'type' => 'medical_gp', 'incurred_on' => '2026-05-10',
                        'description' => 'Dependent clinic visit',
                        'requested_amount' => 60.00, 'provider_name' => 'Poliklinik Bunga Raya',
                        'receipt_number' => 'PBR-100', 'attachment_count' => 1,
                    ],
                ],
                'audit' => [],
            ],
        ];

        foreach ($scenarios as $scenario) {
            $employee = $employees->values()->get($scenario['employee_index']);
            if ($employee === null) {
                continue;
            }

            $existing = ClaimRequest::query()
                ->where('company_id', $company->id)
                ->where('employee_id', $employee->id)
                ->when(
                    $scenario['reference'] !== null,
                    fn ($q) => $q->where('reference_number', $scenario['reference']),
                    fn ($q) => $q->where('status', ClaimRequest::STATUS_DRAFT),
                )
                ->first();

            if ($existing !== null) {
                continue;
            }

            $requestedTotal = 0.0;
            $approvedTotal = 0.0;
            foreach ($scenario['lines'] as $line) {
                $requestedTotal += (float) $line['requested_amount'];
                $approvedTotal += (float) ($line['approved_amount'] ?? 0);
            }

            $request = ClaimRequest::query()->create([
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'claim_assignment_id' => $assignment->id,
                'claim_context_id' => $scenario['context']?->id,
                'reference_number' => $scenario['reference'],
                'status' => $scenario['status'],
                'currency' => 'MYR',
                'requested_amount' => $requestedTotal,
                'approved_amount' => $approvedTotal,
                'reimbursed_amount' => 0,
                'submitted_at' => $scenario['submitted_at'] ?? null,
                'approved_at' => $scenario['approved_at'] ?? null,
                'rejected_at' => $scenario['rejected_at'] ?? null,
                'decision_reason' => $scenario['decision_reason'] ?? null,
                'metadata' => ['scenario' => 'browser-demo'],
            ]);

            foreach ($scenario['lines'] as $line) {
                $type = $types[$line['type']] ?? null;
                $policy = $policies[$line['type']] ?? null;
                if ($type === null) {
                    continue;
                }

                $assignmentLine = $assignmentLinesByType->get($type->id);

                ClaimLine::query()->create([
                    'claim_request_id' => $request->id,
                    'claim_type_id' => $type->id,
                    'claim_policy_id' => $policy?->id,
                    'claim_assignment_line_id' => $assignmentLine?->id,
                    'incurred_on' => $line['incurred_on'],
                    'description' => $line['description'] ?? null,
                    'unit' => $line['unit'] ?? ClaimType::UNIT_AMOUNT,
                    'quantity' => $line['quantity'] ?? 1,
                    'rate' => $line['rate'] ?? null,
                    'requested_amount' => $line['requested_amount'],
                    'approved_amount' => $line['approved_amount'] ?? 0,
                    'reimbursed_amount' => 0,
                    'currency' => 'MYR',
                    'provider_name' => $line['provider_name'] ?? null,
                    'receipt_number' => $line['receipt_number'] ?? null,
                    'attachment_count' => $line['attachment_count'] ?? 0,
                    'payroll_pay_item_code' => $type->payroll_pay_item_code,
                    'metadata' => ['scenario' => 'browser-demo'],
                ]);
            }

            foreach ($scenario['audit'] as $event) {
                ClaimRequestAuditEvent::query()->create([
                    'claim_request_id' => $request->id,
                    'from_status' => $event['from'],
                    'to_status' => $event['to'],
                    'reason' => $event['reason'] ?? null,
                    'occurred_at' => $event['at'],
                    'metadata' => ['scenario' => 'browser-demo'],
                ]);
            }
        }
    }
}
