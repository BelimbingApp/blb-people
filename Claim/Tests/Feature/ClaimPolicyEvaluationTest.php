<?php

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Claim\Data\ClaimSubmissionInput;
use App\Modules\People\Claim\Models\ClaimAssignment;
use App\Modules\People\Claim\Models\ClaimAssignmentLine;
use App\Modules\People\Claim\Models\ClaimLine;
use App\Modules\People\Claim\Models\ClaimPolicy;
use App\Modules\People\Claim\Models\ClaimPolicyBand;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Models\ClaimType;
use App\Modules\People\Claim\Services\ClaimPolicyEvaluationService;

/**
 * @return array{employee: Employee, type: ClaimType, policy: ClaimPolicy, assignment: ClaimAssignment, line: ClaimAssignmentLine}
 */
function makeClaimFixture(array $policyOverrides = []): array
{
    $company = Company::query()->findOrFail(Company::LICENSEE_ID);
    $employee = Employee::factory()->create(['company_id' => $company->id]);

    $type = ClaimType::query()->create([
        'company_id' => $company->id,
        'code' => 'medical_gp_'.uniqid(),
        'name' => 'Medical GP',
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

    $policy = ClaimPolicy::query()->create(array_merge([
        'company_id' => $company->id,
        'code' => 'med_policy_'.uniqid(),
        'name' => 'Medical Policy',
        'item_mode' => ClaimPolicy::MODE_SINGLE_VALUE,
        'encumber_pending' => true,
        'effective_from' => '2026-01-01',
        'version' => 1,
        'status' => 'active',
    ], $policyOverrides));

    ClaimPolicyBand::query()->create([
        'claim_policy_id' => $policy->id,
        'logical_operator' => '<=',
        'threshold_value' => null,
        'rate' => 1,
        'per_claim_limit' => 500.00,
        'per_month_limit' => 500.00,
        'per_year_limit' => 2000.00,
        'sort_order' => 10,
    ]);

    $assignment = ClaimAssignment::query()->create([
        'company_id' => $company->id,
        'code' => 'asg_'.uniqid(),
        'name' => 'Default Assignment',
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

    return [
        'employee' => $employee,
        'type' => $type,
        'policy' => $policy,
        'assignment' => $assignment,
        'line' => $line,
    ];
}

function makeClaimWith(array $f, string $status, float $requested, float $approved = 0.0, string $incurred = '2026-06-10'): ClaimRequest
{
    /** @var Employee $employee */
    $employee = $f['employee'];

    $request = ClaimRequest::query()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'claim_assignment_id' => $f['assignment']->id,
        'reference_number' => 'CLM-TEST-'.uniqid(),
        'status' => $status,
        'currency' => 'MYR',
        'requested_amount' => $requested,
        'approved_amount' => $approved,
        'reimbursed_amount' => 0,
        'submitted_at' => now(),
        'metadata' => [],
    ]);

    ClaimLine::query()->create([
        'claim_request_id' => $request->id,
        'claim_type_id' => $f['type']->id,
        'claim_policy_id' => $f['policy']->id,
        'claim_assignment_line_id' => $f['line']->id,
        'incurred_on' => $incurred,
        'unit' => ClaimType::UNIT_AMOUNT,
        'quantity' => 1,
        'rate' => $requested,
        'requested_amount' => $requested,
        'approved_amount' => $approved,
        'reimbursed_amount' => 0,
        'currency' => 'MYR',
        'metadata' => [],
    ]);

    return $request;
}

function evaluateSubmission(array $f, float $newRequested, string $incurred = '2026-06-15'): array
{
    return app(ClaimPolicyEvaluationService::class)->evaluateBeforeSubmission(
        claimType: $f['type'],
        policy: $f['policy'],
        input: new ClaimSubmissionInput(
            employeeId: $f['employee']->id,
            incurredOn: new DateTimeImmutable($incurred),
            requestedAmount: $newRequested,
            attachmentCount: 0,
            providerName: null,
            employee: $f['employee'],
        ),
    );
}

it('approved claims consume only approved_amount toward the cap', function () {
    // Per-month cap is 500. Prior request was requested 500, approved 200.
    $f = makeClaimFixture();
    makeClaimWith($f, ClaimRequest::STATUS_APPROVED, requested: 500.00, approved: 200.00, incurred: '2026-06-05');

    // A new 350 request should fit: 200 used + 350 = 550 > 500 fails... need 200 + 300 = 500 (ok)
    $resultOk = evaluateSubmission($f, 300.00, incurred: '2026-06-15');
    expect($resultOk['blocking'])->toBe([]);

    $resultBlocked = evaluateSubmission($f, 350.00, incurred: '2026-06-15');
    expect($resultBlocked['blocking'])->not->toBe([]);
});

it('pending claims encumber requested_amount when policy.encumber_pending is true', function () {
    $f = makeClaimFixture(['encumber_pending' => true]);
    makeClaimWith($f, ClaimRequest::STATUS_SUBMITTED, requested: 400.00, incurred: '2026-06-05');

    // 400 encumbered + 200 new = 600 > 500 monthly cap
    $result = evaluateSubmission($f, 200.00, incurred: '2026-06-15');
    expect($result['blocking'])->not->toBe([]);
});

it('pending claims are ignored when policy.encumber_pending is false', function () {
    $f = makeClaimFixture(['encumber_pending' => false]);
    makeClaimWith($f, ClaimRequest::STATUS_SUBMITTED, requested: 400.00, incurred: '2026-06-05');

    // Pending 400 should NOT block a fresh 200 request because policy opts out
    $result = evaluateSubmission($f, 200.00, incurred: '2026-06-15');
    expect($result['blocking'])->toBe([]);
});

it('rejected/cancelled/withdrawn claims do not consume cap', function () {
    $f = makeClaimFixture();
    makeClaimWith($f, ClaimRequest::STATUS_REJECTED, requested: 500.00, incurred: '2026-06-01');
    makeClaimWith($f, ClaimRequest::STATUS_CANCELLED, requested: 500.00, incurred: '2026-06-02');
    makeClaimWith($f, ClaimRequest::STATUS_WITHDRAWN, requested: 500.00, incurred: '2026-06-03');

    $result = evaluateSubmission($f, 500.00, incurred: '2026-06-15');
    expect($result['blocking'])->toBe([]);
});

it('queued_for_payroll and reimbursed claims consume approved_amount', function () {
    $f = makeClaimFixture();
    makeClaimWith($f, ClaimRequest::STATUS_QUEUED_FOR_PAYROLL, requested: 500.00, approved: 150.00, incurred: '2026-06-01');
    makeClaimWith($f, ClaimRequest::STATUS_REIMBURSED, requested: 500.00, approved: 150.00, incurred: '2026-06-02');

    // 300 consumed + 250 = 550 (over 500)
    $blocked = evaluateSubmission($f, 250.00, incurred: '2026-06-15');
    expect($blocked['blocking'])->not->toBe([]);

    // 300 consumed + 200 = 500 (ok)
    $ok = evaluateSubmission($f, 200.00, incurred: '2026-06-15');
    expect($ok['blocking'])->toBe([]);
});
