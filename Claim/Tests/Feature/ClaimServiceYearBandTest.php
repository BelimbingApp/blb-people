<?php

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Claim\Data\ClaimSubmissionInput;
use App\Modules\People\Claim\Models\ClaimAssignment;
use App\Modules\People\Claim\Models\ClaimAssignmentLine;
use App\Modules\People\Claim\Models\ClaimPolicy;
use App\Modules\People\Claim\Models\ClaimPolicyBand;
use App\Modules\People\Claim\Models\ClaimType;
use App\Modules\People\Claim\Services\ClaimPolicyEvaluationService;

/**
 * Build a service-year-banded policy:
 *   <= 2 years → per_claim 100
 *   <= 5 years → per_claim 300
 *   no cap    → per_claim 500
 */
function makeServiceYearFixture(string $employmentStart): array
{
    $company = Company::query()->findOrFail(Company::LICENSEE_ID);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'employment_start' => $employmentStart,
    ]);

    $type = ClaimType::query()->create([
        'company_id' => $company->id,
        'code' => 'training_'.uniqid(),
        'name' => 'Training',
        'default_unit' => ClaimType::UNIT_AMOUNT,
        'calculation_mode' => 'manual_amount',
        'receipt_requirement' => ClaimType::RECEIPT_NEVER,
        'provider_required' => false,
        'payroll_eligible' => true,
        'sort_order' => 10,
        'allow_employee_submission' => true,
        'allow_on_behalf_submission' => true,
        'admin_only' => false,
        'advance_settlement_allowed' => false,
        'status' => ClaimType::STATUS_ACTIVE,
    ]);

    $policy = ClaimPolicy::query()->create([
        'company_id' => $company->id,
        'code' => 'training_policy_'.uniqid(),
        'name' => 'Training Policy',
        'item_mode' => ClaimPolicy::MODE_SERVICE_YEAR,
        'encumber_pending' => true,
        'effective_from' => '2026-01-01',
        'version' => 1,
        'status' => 'active',
    ]);

    foreach ([[2, 100.0], [5, 300.0], [null, 500.0]] as $idx => [$threshold, $cap]) {
        ClaimPolicyBand::query()->create([
            'claim_policy_id' => $policy->id,
            'logical_operator' => '<=',
            'threshold_value' => $threshold,
            'rate' => 1,
            'per_claim_limit' => $cap,
            'sort_order' => ($idx + 1) * 10,
        ]);
    }

    $assignment = ClaimAssignment::query()->create([
        'company_id' => $company->id,
        'code' => 'asg_'.uniqid(),
        'name' => 'Service-Year Assignment',
        'effective_from' => '2026-01-01',
        'status' => 'active',
    ]);

    $line = ClaimAssignmentLine::query()->create([
        'claim_assignment_id' => $assignment->id,
        'claim_type_id' => $type->id,
        'claim_policy_id' => $policy->id,
        'sort_order' => 10,
        'status' => ClaimAssignmentLine::STATUS_ACTIVE,
    ]);

    return compact('employee', 'type', 'policy', 'assignment', 'line');
}

function evaluateSvc(array $f, float $amount, string $incurred): array
{
    return app(ClaimPolicyEvaluationService::class)->evaluateBeforeSubmission(
        claimType: $f['type'],
        policy: $f['policy'],
        input: new ClaimSubmissionInput(
            employeeId: $f['employee']->id,
            incurredOn: new DateTimeImmutable($incurred),
            requestedAmount: $amount,
            attachmentCount: 0,
            providerName: null,
            employee: $f['employee'],
        ),
    );
}

it('matches the 0-2y band for new joiners', function () {
    // Joined 2025-06-01, incurred 2026-06-15 → ~1 year service → 0-2y band (cap 100)
    $f = makeServiceYearFixture('2025-06-01');

    $ok = evaluateSvc($f, 100.00, '2026-06-15');
    expect($ok['blocking'])->toBe([]);

    $blocked = evaluateSvc($f, 150.00, '2026-06-15');
    expect($blocked['blocking'])->not->toBe([]);
});

it('matches the 2-5y band for mid-tenure employees', function () {
    // Joined 2022-06-01, incurred 2026-06-15 → ~4 years service → 2-5y band (cap 300)
    $f = makeServiceYearFixture('2022-06-01');

    $ok = evaluateSvc($f, 300.00, '2026-06-15');
    expect($ok['blocking'])->toBe([]);

    $blocked = evaluateSvc($f, 350.00, '2026-06-15');
    expect($blocked['blocking'])->not->toBe([]);
});

it('matches the unlimited (>5y) band for long-tenure employees', function () {
    // Joined 2015-06-01, incurred 2026-06-15 → ~11 years service → no-threshold band (cap 500)
    $f = makeServiceYearFixture('2015-06-01');

    $ok = evaluateSvc($f, 500.00, '2026-06-15');
    expect($ok['blocking'])->toBe([]);

    $blocked = evaluateSvc($f, 600.00, '2026-06-15');
    expect($blocked['blocking'])->not->toBe([]);
});

it('snapshot records the matched service-year band id', function () {
    $f = makeServiceYearFixture('2022-06-01');
    $result = evaluateSvc($f, 100.00, '2026-06-15');

    expect($result['snapshot']['matched_band_id'])->not->toBeNull();
    expect((float) $result['snapshot']['per_claim_limit'])->toBe(300.0);
});
