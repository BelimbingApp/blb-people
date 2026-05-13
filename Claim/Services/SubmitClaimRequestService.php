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

            $this->validateSubmission($employee, $assignment, $assignmentLine, $requestedAmount, $options);

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
                'metadata' => ['source' => 'claim-workbench'],
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
                'provider_name' => $options['provider_name'] ?? null,
                'receipt_number' => $options['receipt_number'] ?? null,
                'attachment_count' => (int) ($options['attachment_count'] ?? 0),
                'payroll_pay_item_code' => $claimType->payroll_pay_item_code,
                'debit_account_code' => $claimType->debit_account_code,
                'credit_account_code' => $claimType->credit_account_code,
                'policy_snapshot' => [
                    'claim_policy_id' => $policy?->getKey(),
                    'claim_policy_code' => $policy?->code,
                    'claim_policy_version' => $policy?->version,
                    'item_mode' => $policy?->item_mode,
                ],
                'accounting_snapshot' => [
                    'payroll_pay_item_code' => $claimType->payroll_pay_item_code,
                    'debit_account_code' => $claimType->debit_account_code,
                    'credit_account_code' => $claimType->credit_account_code,
                ],
                'metadata' => ['source' => 'claim-workbench'],
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

            return $request->refresh();
        });
    }

    private function validateSubmission(
        Employee $employee,
        ClaimAssignment $assignment,
        ClaimAssignmentLine $assignmentLine,
        float $requestedAmount,
        array $options,
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

        if ((bool) $claimType->provider_required && trim((string) ($options['provider_name'] ?? '')) === '') {
            throw ClaimRequestLifecycleException::invalidSubmission(sprintf('%s requires a provider name.', $claimType->name));
        }

        $attachmentCount = (int) ($options['attachment_count'] ?? 0);
        if ($claimType->receipt_requirement === ClaimType::RECEIPT_ALWAYS && $attachmentCount < 1) {
            throw ClaimRequestLifecycleException::invalidSubmission(sprintf('%s requires a receipt attachment.', $claimType->name));
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

    private function nextReferenceNumber(int $companyId): string
    {
        $sequence = ClaimRequest::query()
            ->where('company_id', $companyId)
            ->whereYear('created_at', now()->year)
            ->lockForUpdate()
            ->count() + 1;

        return sprintf('CLM-%d-%05d', now()->year, $sequence);
    }
}
