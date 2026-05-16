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
use Illuminate\Support\Facades\Schema;

class SubmitClaimRequestService
{
    public function __construct(
        private readonly ClaimPolicyEvaluationService $policyEvaluation,
        private readonly ClaimDuplicateRiskService $duplicateRisks,
        private readonly ClaimNotificationDispatcher $notifications,
        private readonly ClaimCohortPredicateService $cohortPredicate,
    ) {}

    /**
     * Single-line submission wrapper kept for callers that have not migrated to {@see submitLines}.
     *
     * @param  array<string, mixed>  $options
     */
    public function submit(
        Employee $employee,
        ClaimAssignment $assignment,
        ClaimAssignmentLine $assignmentLine,
        DateTimeImmutable $incurredOn,
        float $requestedAmount,
        array $options = [],
    ): ClaimRequest {
        return $this->submitLines(
            $employee,
            $assignment,
            [[
                'assignment_line' => $assignmentLine,
                'incurred_on' => $incurredOn,
                'requested_amount' => $requestedAmount,
                'description' => $options['description'] ?? null,
                'provider_name' => $options['provider_name'] ?? null,
                'receipt_number' => $options['receipt_number'] ?? null,
                'attachment_count' => (int) ($options['attachment_count'] ?? 0),
            ]],
            $options,
        );
    }

    /**
     * Submit a claim request with one or more lines.
     *
     * Each line spec resolves its own policy evaluation and duplicate-risk snapshot. All lines must
     * resolve to the same approval profile key (or null) — mixed-profile requests are refused per
     * the v1 strictest-line rule documented in plans/people/08_claim-module-design.md. The header
     * snapshots the strictest line (largest requested_amount).
     *
     * @param  list<array{
     *     assignment_line: ClaimAssignmentLine,
     *     incurred_on: DateTimeImmutable,
     *     requested_amount: float,
     *     description?: ?string,
     *     provider_name?: ?string,
     *     receipt_number?: ?string,
     *     attachment_count?: int,
     *     unit?: ?string,
     *     quantity?: ?float,
     *     rate?: ?float,
     * }>  $lineSpecs
     * @param  array<string, mixed>  $options
     */
    public function submitLines(
        Employee $employee,
        ClaimAssignment $assignment,
        array $lineSpecs,
        array $options = [],
    ): ClaimRequest {
        if ($lineSpecs === []) {
            throw ClaimRequestLifecycleException::invalidSubmission('A claim request must include at least one line.');
        }

        $this->validateOnBehalfOptions($options);

        if (! $this->cohortPredicate->matches($employee, $assignment->cohort_predicate)) {
            throw ClaimRequestLifecycleException::invalidSubmission(sprintf(
                'Employee %s is not eligible for claim assignment [%s].',
                $employee->employee_number ?? (string) $employee->getKey(),
                $assignment->code,
            ));
        }

        return DB::transaction(function () use ($employee, $assignment, $lineSpecs, $options): ClaimRequest {
            $context = $this->resolveContext($assignment->company_id, $options['claim_context_id'] ?? null);
            $currency = (string) ($options['currency'] ?? 'MYR');

            $prepared = [];
            $allBlocking = [];
            $profileKeys = [];
            $requestedTotal = 0.0;
            $strictest = null;
            $allDuplicateRisks = [];

            foreach ($lineSpecs as $index => $spec) {
                $assignmentLine = $spec['assignment_line'];
                $assignmentLine->loadMissing(['type', 'policy']);
                $incurredOn = $spec['incurred_on'];
                $requestedAmount = (float) $spec['requested_amount'];
                $attachmentCount = (int) ($spec['attachment_count'] ?? 0);
                $providerName = $this->blankToNull($spec['provider_name'] ?? null);
                $receiptNumber = $this->blankToNull($spec['receipt_number'] ?? null);

                $this->validateLineSubmission($employee, $assignment, $assignmentLine, $requestedAmount, $options);

                $claimType = $assignmentLine->type;
                $policy = $assignmentLine->policy;

                if ($policy !== null && ! $this->cohortPredicate->matches($employee, $policy->cohort_predicate)) {
                    throw ClaimRequestLifecycleException::invalidSubmission(sprintf(
                        'Employee is not eligible for claim policy [%s] on line %d.',
                        $policy->code,
                        $index + 1,
                    ));
                }

                $evaluation = $this->policyEvaluation->evaluateBeforeSubmission(
                    employeeId: (int) $employee->getKey(),
                    claimType: $claimType,
                    policy: $policy,
                    incurredOn: $incurredOn,
                    requestedAmount: $requestedAmount,
                    attachmentCount: $attachmentCount,
                    providerName: $providerName,
                    combinedClaimTypeIds: $this->combinedClaimTypeIds($assignmentLine),
                    employee: $employee,
                );
                $allBlocking = array_merge($allBlocking, $evaluation['blocking']);

                $duplicateRisks = $this->duplicateRisks->findRisks(
                    employeeId: (int) $employee->getKey(),
                    claimTypeId: (int) $claimType->getKey(),
                    incurredOn: $incurredOn,
                    requestedAmount: $requestedAmount,
                    providerName: $providerName,
                    receiptNumber: $receiptNumber,
                );
                $allDuplicateRisks = array_merge($allDuplicateRisks, $duplicateRisks);

                if ($context !== null && $context->max_claim_limit !== null && $requestedAmount > (float) $context->max_claim_limit) {
                    $allBlocking[] = sprintf('Claim context [%s] limits a line to %.2f.', $context->label, (float) $context->max_claim_limit);
                }

                $approvalProfileKey = $policy?->approval_profile_key ?: $claimType?->approval_route_key;
                if ($approvalProfileKey !== null && $approvalProfileKey !== '') {
                    $profileKeys[$approvalProfileKey] = true;
                }

                $requestedTotal += $requestedAmount;

                $linePayload = [
                    'index' => $index,
                    'assignment_line' => $assignmentLine,
                    'claim_type' => $claimType,
                    'policy' => $policy,
                    'incurred_on' => $incurredOn,
                    'description' => $spec['description'] ?? null,
                    'unit' => $spec['unit'] ?? $claimType->default_unit,
                    'quantity' => $spec['quantity'] ?? 1,
                    'rate' => $spec['rate'] ?? $requestedAmount,
                    'requested_amount' => $requestedAmount,
                    'provider_name' => $providerName,
                    'receipt_number' => $receiptNumber,
                    'attachment_count' => $attachmentCount,
                    'approval_profile_key' => $approvalProfileKey,
                    'policy_snapshot' => $evaluation['snapshot'],
                    'duplicate_risks' => $duplicateRisks,
                ];
                $prepared[] = $linePayload;

                if ($strictest === null || $requestedAmount > (float) $strictest['requested_amount']) {
                    $strictest = $linePayload;
                }
            }

            if ($allBlocking !== []) {
                throw ClaimRequestLifecycleException::invalidSubmission(implode(' ', array_unique($allBlocking)));
            }

            if (count($profileKeys) > 1) {
                throw ClaimRequestLifecycleException::invalidSubmission(sprintf(
                    'Lines require incompatible approval profiles (%s). Split into separate requests.',
                    implode(', ', array_keys($profileKeys)),
                ));
            }

            $headerProfileKey = $strictest['approval_profile_key'];

            $request = ClaimRequest::query()->create([
                'company_id' => $assignment->company_id,
                'employee_id' => $employee->getKey(),
                'claim_assignment_id' => $assignment->getKey(),
                'claim_context_id' => $context?->getKey(),
                'reference_number' => $this->nextReferenceNumber($assignment->company_id),
                'status' => ClaimRequest::STATUS_SUBMITTED,
                'currency' => $currency,
                'requested_amount' => $requestedTotal,
                'approved_amount' => 0,
                'reimbursed_amount' => 0,
                'approval_profile_key' => $headerProfileKey,
                'approval_route_snapshot' => $headerProfileKey === null ? null : ['profile_key' => $headerProfileKey],
                'strictest_line_snapshot' => [
                    'claim_type_id' => $strictest['claim_type']?->getKey(),
                    'claim_type_code' => $strictest['claim_type']?->code,
                    'claim_type_name' => $strictest['claim_type']?->name,
                    'claim_policy_id' => $strictest['policy']?->getKey(),
                    'claim_policy_code' => $strictest['policy']?->code,
                    'approval_profile_key' => $headerProfileKey,
                    'line_index' => $strictest['index'],
                    'requested_amount' => (float) $strictest['requested_amount'],
                ],
                'submitted_by_user_id' => $options['submitted_by_user_id'] ?? null,
                'on_behalf_actor_user_id' => $options['on_behalf_actor_user_id'] ?? null,
                'on_behalf_reason' => $options['on_behalf_reason'] ?? null,
                'submitted_at' => now(),
                'metadata' => [
                    'source' => 'claim-workbench',
                    'duplicate_risks' => $allDuplicateRisks,
                    'line_count' => count($prepared),
                ],
            ]);

            foreach ($prepared as $payload) {
                $claimType = $payload['claim_type'];
                ClaimLine::query()->create([
                    'claim_request_id' => $request->getKey(),
                    'claim_type_id' => $claimType->getKey(),
                    'claim_policy_id' => $payload['policy']?->getKey(),
                    'claim_assignment_line_id' => $payload['assignment_line']->getKey(),
                    'incurred_on' => $payload['incurred_on'],
                    'description' => $payload['description'],
                    'unit' => $payload['unit'],
                    'quantity' => $payload['quantity'],
                    'rate' => $payload['rate'],
                    'requested_amount' => $payload['requested_amount'],
                    'approved_amount' => 0,
                    'reimbursed_amount' => 0,
                    'currency' => $currency,
                    'provider_name' => $payload['provider_name'],
                    'receipt_number' => $payload['receipt_number'],
                    'attachment_count' => $payload['attachment_count'],
                    'payroll_pay_item_code' => $this->resolvePayItemCode($claimType, $payload['incurred_on']),
                    'debit_account_code' => $claimType->debit_account_code,
                    'credit_account_code' => $claimType->credit_account_code,
                    'policy_snapshot' => $payload['policy_snapshot'],
                    'accounting_snapshot' => [
                        'payroll_pay_item_code' => $this->resolvePayItemCode($claimType, $payload['incurred_on']),
                        'debit_account_code' => $claimType->debit_account_code,
                        'credit_account_code' => $claimType->credit_account_code,
                    ],
                    'metadata' => [
                        'source' => 'claim-workbench',
                        'duplicate_risks' => $payload['duplicate_risks'],
                    ],
                ]);
            }

            ClaimRequestAuditEvent::query()->create([
                'claim_request_id' => $request->getKey(),
                'from_status' => ClaimRequest::STATUS_DRAFT,
                'to_status' => ClaimRequest::STATUS_SUBMITTED,
                'actor_user_id' => $options['submitted_by_user_id'] ?? $options['on_behalf_actor_user_id'] ?? null,
                'reason' => 'submitted',
                'occurred_at' => now(),
                'metadata' => [
                    'requested_amount' => $requestedTotal,
                    'line_count' => count($prepared),
                ],
            ]);

            $request = $request->refresh();
            $this->notifications->dispatch(ClaimNotificationDispatcher::EVENT_SUBMITTED, $request, [
                'submitted_by_user_id' => $options['submitted_by_user_id'] ?? null,
                'on_behalf_actor_user_id' => $options['on_behalf_actor_user_id'] ?? null,
            ]);

            return $request;
        });
    }

    private function validateOnBehalfOptions(array $options): void
    {
        $actor = $options['on_behalf_actor_user_id'] ?? null;
        $reason = isset($options['on_behalf_reason']) ? trim((string) $options['on_behalf_reason']) : '';

        if ($actor !== null && $reason === '') {
            throw ClaimRequestLifecycleException::invalidSubmission('On-behalf submissions require a reason.');
        }

        if ($actor === null && $reason !== '') {
            throw ClaimRequestLifecycleException::invalidSubmission('On-behalf reason supplied without an on-behalf actor.');
        }
    }

    private function validateLineSubmission(
        Employee $employee,
        ClaimAssignment $assignment,
        ClaimAssignmentLine $assignmentLine,
        float $requestedAmount,
        array $options = [],
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
        $onBehalf = ($options['on_behalf_actor_user_id'] ?? null) !== null;

        if ($claimType->status !== ClaimType::STATUS_ACTIVE) {
            throw ClaimRequestLifecycleException::invalidSubmission('The selected claim type is not active.');
        }

        if ($onBehalf) {
            if (! (bool) $claimType->allow_on_behalf_submission) {
                throw ClaimRequestLifecycleException::invalidSubmission('The selected claim type is not open for on-behalf submission.');
            }
        } elseif (! (bool) $claimType->allow_employee_submission) {
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

    /**
     * Resolve the pay-item code for a claim type effective at a given date.
     *
     * Reads from the Payroll-owned mapping table
     * (people_payroll_claim_type_pay_items). Returns null when the table
     * is absent (Payroll plugin uninstalled) so the line snapshot is
     * left empty rather than crashing the submission.
     */
    private function resolvePayItemCode(ClaimType $claimType, mixed $incurredOn): ?string
    {
        if (! Schema::hasTable('people_payroll_claim_type_pay_items')) {
            return null;
        }

        $date = $incurredOn instanceof \DateTimeInterface
            ? $incurredOn->format('Y-m-d')
            : (string) $incurredOn;

        $row = DB::table('people_payroll_claim_type_pay_items')
            ->where('claim_type_id', $claimType->getKey())
            ->where('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>', $date);
            })
            ->orderByDesc('effective_from')
            ->first(['payroll_pay_item_code']);

        $code = $row?->payroll_pay_item_code;

        return is_string($code) && $code !== '' ? $code : null;
    }
}
