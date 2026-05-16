<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\People\Claim\Events\ClaimReimbursementQueued;
use App\Modules\People\Claim\Events\ClaimReimbursementReversed;
use App\Modules\People\Claim\Models\ClaimLine;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Models\ClaimRequestAuditEvent;
use DateTimeImmutable;

class ClaimPayrollHandoffService
{
    public const SOURCE_TYPE = 'claim_line';

    public function __construct(
        private readonly ClaimNotificationDispatcher $notifications,
    ) {}

    /**
     * @return array{eligible: int, dispatched: int, skipped: int}
     */
    public function queueApprovedRequest(ClaimRequest $request, ?int $actorUserId = null): array
    {
        $request->loadMissing(['lines.type']);

        $eligible = 0;
        $dispatched = 0;
        $skipped = 0;

        foreach ($request->lines as $line) {
            if (! $this->isPayrollEligible($line)) {
                $skipped++;

                continue;
            }

            $eligible++;
            event($this->buildEvent($request, $line));
            $dispatched++;
        }

        $summary = compact('eligible', 'dispatched', 'skipped');
        $metadata = is_array($request->metadata) ? $request->metadata : [];
        $metadata['payroll_handoff'] = $summary;
        $request->metadata = $metadata;

        if ($eligible > 0 && $dispatched === $eligible) {
            $fromStatus = $request->status;
            $request->status = ClaimRequest::STATUS_QUEUED_FOR_PAYROLL;
            $request->queued_for_payroll_at = now();

            ClaimRequestAuditEvent::query()->create([
                'claim_request_id' => $request->getKey(),
                'from_status' => $fromStatus,
                'to_status' => ClaimRequest::STATUS_QUEUED_FOR_PAYROLL,
                'actor_user_id' => $actorUserId,
                'reason' => 'queued_for_payroll',
                'occurred_at' => now(),
                'metadata' => $summary,
            ]);

            $this->notifications->dispatch(ClaimNotificationDispatcher::EVENT_PAYROLL_QUEUED, $request, $summary + [
                'actor_user_id' => $actorUserId,
            ]);
        }

        $request->save();

        return $summary;
    }

    /**
     * Reverse all payroll contributions for a claim request's lines.
     * Per-line ClaimReimbursementReversed events are dispatched; the
     * downstream listener calls intake->reverse() (delete-if-draft or
     * compensating-row).
     */
    public function reverseRequest(ClaimRequest $request, ?string $reason = null): void
    {
        $request->loadMissing('lines');

        foreach ($request->lines as $line) {
            if (! $line->payroll_pay_item_code) {
                continue;
            }

            event(new ClaimReimbursementReversed(
                claimRequestId: (int) $request->getKey(),
                claimLineId: (int) $line->getKey(),
                payItemCode: (string) $line->payroll_pay_item_code,
                occurredOn: $this->periodAnchor($line),
                reason: $reason,
            ));
        }
    }

    private function buildEvent(ClaimRequest $request, ClaimLine $line): ClaimReimbursementQueued
    {
        $anchor = $this->periodAnchor($line);

        return new ClaimReimbursementQueued(
            companyId: (int) $request->company_id,
            employeeId: (int) $request->employee_id,
            claimRequestId: (int) $request->getKey(),
            claimLineId: (int) $line->getKey(),
            claimTypeId: (int) $line->claim_type_id,
            payItemCode: (string) $line->payroll_pay_item_code,
            currency: (string) ($line->currency ?? $request->currency ?? 'MYR'),
            occurredOn: $anchor,
            amount: (float) $line->approved_amount,
            label: $line->type?->name ?? 'Claim reimbursement',
            accountingSnapshot: $this->accountingSnapshot($line),
            metadata: [
                'claim_request_id' => $request->getKey(),
                'claim_reference_number' => $request->reference_number,
                'claim_line_id' => $line->getKey(),
                'claim_type_id' => $line->claim_type_id,
                'claim_type_code' => $line->type?->code,
            ],
        );
    }

    private function periodAnchor(ClaimLine $line): DateTimeImmutable
    {
        $date = $line->incurred_on instanceof \DateTimeInterface
            ? $line->incurred_on->format('Y-m-d')
            : (string) $line->incurred_on;

        return new DateTimeImmutable($date);
    }

    /** @return array<string, mixed> */
    private function accountingSnapshot(ClaimLine $line): array
    {
        $stored = is_array($line->accounting_snapshot) ? $line->accounting_snapshot : [];

        return $stored + [
            'payroll_pay_item_code' => $line->payroll_pay_item_code,
            'debit_account_code' => $line->debit_account_code,
            'credit_account_code' => $line->credit_account_code,
        ];
    }

    private function isPayrollEligible(ClaimLine $line): bool
    {
        return (bool) $line->type?->payroll_eligible
            && $line->payroll_pay_item_code !== null
            && (float) $line->approved_amount > 0;
    }
}
