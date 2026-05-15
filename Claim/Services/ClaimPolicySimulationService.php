<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Claim\Models\ClaimAssignmentLine;
use DateTimeImmutable;

/**
 * "What if" evaluator for a claim policy + line spec + employee tuple.
 *
 * Reuses the existing {@see ClaimPolicyEvaluationService::evaluateBeforeSubmission} so the
 * simulator reflects exactly what would happen at real submission time — but without persisting
 * anything. Output shape mirrors AttendancePolicySimulationService:
 *
 *   { status, policy, input, matched_band, blocking[], explanation }
 *
 * Used by the policy group validator's "Simulate" lens, by CLI, and (in future) by an admin "test this
 * policy against an employee" affordance before assignment.
 */
class ClaimPolicySimulationService
{
    public function __construct(
        private readonly ClaimPolicyEvaluationService $policyEvaluation,
        private readonly ClaimCohortPredicateService $cohortPredicate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function simulate(
        Employee $employee,
        ClaimAssignmentLine $assignmentLine,
        DateTimeImmutable $incurredOn,
        float $requestedAmount,
        int $attachmentCount = 0,
        ?string $providerName = null,
    ): array {
        $assignmentLine->loadMissing(['type', 'policy', 'assignment']);
        $claimType = $assignmentLine->type;
        $policy = $assignmentLine->policy;
        $assignment = $assignmentLine->assignment;

        $cohortBlocking = [];
        if ($assignment !== null && ! $this->cohortPredicate->matches($employee, $assignment->cohort_predicate)) {
            $cohortBlocking[] = sprintf('Employee is not eligible for assignment [%s].', $assignment->code);
        }
        if ($policy !== null && ! $this->cohortPredicate->matches($employee, $policy->cohort_predicate)) {
            $cohortBlocking[] = sprintf('Employee is not eligible for policy [%s].', $policy->code);
        }

        $evaluation = $this->policyEvaluation->evaluateBeforeSubmission(
            employeeId: (int) $employee->getKey(),
            claimType: $claimType,
            policy: $policy,
            incurredOn: $incurredOn,
            requestedAmount: $requestedAmount,
            attachmentCount: $attachmentCount,
            providerName: $providerName,
            employee: $employee,
        );

        $blocking = [...$cohortBlocking, ...$evaluation['blocking']];

        return [
            'status' => $blocking === [] ? 'ok' : 'blocked',
            'policy' => [
                'id' => $policy?->id,
                'code' => $policy?->code,
                'name' => $policy?->name,
                'item_mode' => $policy?->item_mode,
                'version' => $policy?->version,
            ],
            'input' => [
                'employee_id' => $employee->getKey(),
                'employee_number' => $employee->employee_number,
                'claim_type_code' => $claimType?->code,
                'incurred_on' => $incurredOn->format('Y-m-d'),
                'requested_amount' => $requestedAmount,
                'attachment_count' => $attachmentCount,
                'provider_name' => $providerName,
            ],
            'matched_band' => [
                'id' => $evaluation['snapshot']['matched_band_id'] ?? null,
                'threshold_value' => $evaluation['snapshot']['threshold_value'] ?? null,
                'per_claim_limit' => $evaluation['snapshot']['per_claim_limit'] ?? null,
                'per_month_limit' => $evaluation['snapshot']['per_month_limit'] ?? null,
                'per_year_limit' => $evaluation['snapshot']['per_year_limit'] ?? null,
            ],
            'requirements' => [
                'receipt_required' => $evaluation['snapshot']['receipt_required'] ?? false,
                'provider_required' => $evaluation['snapshot']['provider_required'] ?? false,
            ],
            'blocking' => $blocking,
            'explanation' => $this->explanation($blocking, $evaluation['snapshot'] ?? [], $requestedAmount),
        ];
    }

    /** @param  array<int, string>  $blocking @param  array<string, mixed>  $snapshot */
    private function explanation(array $blocking, array $snapshot, float $requestedAmount): string
    {
        if ($blocking === []) {
            $bandCap = $snapshot['per_claim_limit'] ?? null;
            $bandLine = $bandCap !== null
                ? sprintf(' Matched band per-claim cap: %.2f.', (float) $bandCap)
                : '';

            return sprintf('Request for %.2f would be accepted under policy [%s].%s', $requestedAmount, $snapshot['claim_policy_code'] ?? 'unknown', $bandLine);
        }

        return 'Request would be rejected: '.implode(' ', $blocking);
    }
}
