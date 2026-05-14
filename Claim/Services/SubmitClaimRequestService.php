<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Claim\Exceptions\ClaimRequestLifecycleException;
use App\Modules\People\Claim\Models\ClaimAssignment;
use App\Modules\People\Claim\Models\ClaimAssignmentLine;
use App\Modules\People\Claim\Models\ClaimContext;
use App\Modules\People\Claim\Models\ClaimLine;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Models\ClaimRequestAuditEvent;
use App\Modules\People\Claim\Models\ClaimType;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class SubmitClaimRequestService
{
    public function __construct(
        private readonly ClaimPolicyEvaluationService $policyEvaluation,
        private readonly ClaimDuplicateRiskService $duplicateRisks,
        private readonly ClaimNotificationDispatcher $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $options  Optional: claim_context_id, description, provider_name, receipt_number, attachment_count, submitted_by_user_id, on_behalf_actor_user_id, on_behalf_reason, currency.
     */
    public function submit(
        Employee $employee,
        ClaimAssignment $assignment,
        ClaimAssignmentLine $assignmentLine,
        DateTimeImmutable $incurredOn,
        float $requestedAmount,
        array $options = [],
    ): ClaimRequest {
        return DB::transaction(function () use ($employee, $assignment, $assignmentLine, $incurredOn, $requestedAmount, $options): ClaimRequest {
            $assignmentLine->loadMissing(['type', 'policy']);
            $claimType = $assignmentLine->type;
            $policy = $assignmentLine->policy;

            $this->validateSubmission($employee, $assignment, $assignmentLine, $requestedAmount);
            $attachmentCount = (int) ($options['attachment_count'] ?? 0);
            $providerName = $this->blankToNull($options['provider_name'] ?? null);
            $receiptNumber = $this->blankToNull($options['receipt_number'] ?? null);

            $policyEvaluation = $this->policyEvaluation->evaluateBeforeSubmission(
                employeeId: (int) $employee->getKey(),
                claimType: $claimType,
                policy: $policy,
                incurredOn: $incurredOn,
                requestedAmount: $requestedAmount,
                attachmentCount: $attachmentCount,
                providerName: $providerName,
                combinedClaimTypeIds: $this->combinedClaimTypeIds($assignmentLine),
            );

            if ($policyEvaluation['blocking'] !== []) {
                throw ClaimRequestLifecycleException::invalidSubmission(implode(' ', $policyEvaluation['blocking']));
            }

            $duplicateRisks = $this->duplicateRisks->findRisks(
                employeeId: (int) $employee->getKey(),
                claimTypeId: (int) $claimType->getKey(),
                incurredOn: $incurredOn,
                requestedAmount: $requestedAmount,
                providerName: $providerName,
                receiptNumber: $receiptNumber,
            );

            $context = $this->resolveContext($assignment->company_id, $options['claim_context_id'] ?? null);
            if ($context !== null && $context->max_claim_limit !== null && $requestedAmount > (float) $context->max_claim_limit) {
                throw ClaimRequestLifecycleException::invalidSubmission(sprintf(
                    'Claim context [%s] limits a request to %.2f.',
                    $context->label,
                    (float) $context->max_claim_limit,
                ));
            }

            $currency = (string) ($options['currency'] ?? 'MYR');
            $approvalProfileKey = $policy?->approval_profile_key ?: $claimType?->approval_route_key;

            $request = ClaimRequest::query()->create([
                'company_id' => $assignment->company_id,
                'employee_id' => $employee->getKey(),
                'claim_assignment_id' => $assignment->getKey(),
                'claim_context_id' => $context?->getKey(),
                'reference_number' => $this->nextReferenceNumber($assignment->company_id),
                'status' => ClaimRequest::STATUS_SUBMITTED,
                'currency' => $currency,
                'requested_amount' => $requestedAmount,
                'approved_amount' => 0,
                'reimbursed_amount' => 0,
                'approval_profile_key' => $approvalProfileKey,
                'approval_route_snapshot' => $approvalProfileKey === null ? null : ['profile_key' => $approvalProfileKey],
                'strictest_line_snapshot' => [
                    'claim_type_id' => $claimType?->getKey(),
                    'claim_type_code' => $claimType?->code,
                    'claim_type_name' => $claimType?->name,
                    'claim_policy_id' => $policy?->getKey(),
                    'claim_policy_code' => $policy?->code,
                    'approval_profile_key' => $approvalProfileKey,
                ],
                'submitted_by_user_id' => $options['submitted_by_user_id'] ?? null,
                'on_behalf_actor_user_id' => $options['on_behalf_actor_user_id'] ?? null,
                'on_behalf_reason' => $options['on_behalf_reason'] ?? null,
                'submitted_at' => now(),
                'metadata' => [
                    'source' => 'claim-workbench',
                    'duplicate_risks' => $duplicateRisks,
                ],
            ]);

            ClaimLine::query()->create([
                'claim_request_id' => $request->getKey(),
                'claim_type_id' => $claimType->getKey(),
                'claim_policy_id' => $policy?->getKey(),
                'claim_assignment_line_id' => $assignmentLine->getKey(),
                'incurred_on' => $incurredOn,
                'description' => $options['description'] ?? null,
                'unit' => $claimType->default_unit,
                'quantity' => 1,
                'rate' => $requestedAmount,
                'requested_amount' => $requestedAmount,
                'approved_amount' => 0,
                'reimbursed_amount' => 0,
                'currency' => $currency,
                'provider_name' => $providerName,
                'receipt_number' => $receiptNumber,
                'attachment_count' => $attachmentCount,
                'payroll_pay_item_code' => $claimType->payroll_pay_item_code,
                'debit_account_code' => $claimType->debit_account_code,
                'credit_account_code' => $claimType->credit_account_code,
                'policy_snapshot' => $policyEvaluation['snapshot'],
                'accounting_snapshot' => [
                    'payroll_pay_item_code' => $claimType->payroll_pay_item_code,
                    'debit_account_code' => $claimType->debit_account_code,
                    'credit_account_code' => $claimType->credit_account_code,
                ],
                'metadata' => [
                    'source' => 'claim-workbench',
                    'duplicate_risks' => $duplicateRisks,
                ],
            ]);

            ClaimRequestAuditEvent::query()->create([
                'claim_request_id' => $request->getKey(),
                'from_status' => ClaimRequest::STATUS_DRAFT,
                'to_status' => ClaimRequest::STATUS_SUBMITTED,
                'actor_user_id' => $options['submitted_by_user_id'] ?? $options['on_behalf_actor_user_id'] ?? null,
                'reason' => 'submitted',
                'occurred_at' => now(),
                'metadata' => ['requested_amount' => $requestedAmount],
            ]);

            $request = $request->refresh();
            $this->notifications->dispatch(ClaimNotificationDispatcher::EVENT_SUBMITTED, $request, [
                'submitted_by_user_id' => $options['submitted_by_user_id'] ?? null,
            ]);

            return $request;
        });
    }

    private function validateSubmission(
        Employee $employee,
        ClaimAssignment $assignment,
        ClaimAssignmentLine $assignmentLine,
        float $requestedAmount,
    ): void {
        if ((int) $employee->company_id !== (int) $assignment->company_id) {
            throw ClaimRequestLifecycleException::invalidSubmission('The employee does not belong to the claim assignment company.');
        }

        if ((int) $assignmentLine->claim_assignment_id !== (int) $assignment->getKey()) {
            throw ClaimRequestLifecycleException::invalidSubmission('The selected claim type is not part of the selected assignment.');
        }

        if ($assignment->status !== ClaimAssignment::STATUS_ACTIVE || $assignmentLine->status !== ClaimAssignmentLine::STATUS_ACTIVE) {
            throw ClaimRequestLifecycleException::invalidSubmission('The selected claim assignment is not active.');
        }

        if ((bool) $assignmentLine->hidden_from_application) {
            throw ClaimRequestLifecycleException::invalidSubmission('The selected claim type is hidden from employee applications.');
        }

        if ($requestedAmount <= 0) {
            throw ClaimRequestLifecycleException::invalidSubmission('Claim amount must be greater than zero.');
        }

        $claimType = $assignmentLine->type;
        if ($claimType->status !== ClaimType::STATUS_ACTIVE || ! (bool) $claimType->allow_employee_submission) {
            throw ClaimRequestLifecycleException::invalidSubmission('The selected claim type is not open for employee submission.');
        }

    }

    private function resolveContext(int $companyId, mixed $contextId): ?ClaimContext
    {
        if ($contextId === null || $contextId === '') {
            return null;
        }

        return ClaimContext::query()
            ->where('company_id', $companyId)
            ->where('status', ClaimContext::STATUS_ACTIVE)
            ->findOrFail((int) $contextId);
    }

    /** @return list<int> */
    private function combinedClaimTypeIds(ClaimAssignmentLine $assignmentLine): array
    {
        if (! (bool) $assignmentLine->uses_combined_cap || $assignmentLine->combine_tag === null || trim($assignmentLine->combine_tag) === '') {
            return [];
        }

        return ClaimAssignmentLine::query()
            ->where('claim_assignment_id', $assignmentLine->claim_assignment_id)
            ->where('status', ClaimAssignmentLine::STATUS_ACTIVE)
            ->where('uses_combined_cap', true)
            ->where('combine_tag', $assignmentLine->combine_tag)
            ->pluck('claim_type_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function nextReferenceNumber(int $companyId): string
    {
        $year = (int) now()->year;
        $prefix = sprintf('CLM-%d-', $year);

        $latest = ClaimRequest::query()
            ->where('company_id', $companyId)
            ->where('reference_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('reference_number');

        $sequence = $latest === null
            ? 1
            : ((int) substr((string) $latest, strrpos((string) $latest, '-') + 1)) + 1;

        return sprintf('CLM-%d-%05d', $year, $sequence);
    }

    private function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
