<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\People\Claim\Models\ClaimLine;
use App\Modules\People\Claim\Models\ClaimRequest;
use DateTimeImmutable;

class ClaimDuplicateRiskService
{
    /**
     * @return list<array{code: string, message: string, matching_claim_line_id: int|null, matching_claim_request_id: int|null}>
     */
    public function findRisks(
        int $employeeId,
        int $claimTypeId,
        DateTimeImmutable $incurredOn,
        float $requestedAmount,
        ?string $providerName,
        ?string $receiptNumber,
    ): array {
        $risks = [];
        $activeStatuses = [
            ClaimRequest::STATUS_DRAFT,
            ClaimRequest::STATUS_SUBMITTED,
            ClaimRequest::STATUS_NEEDS_MORE_INFO,
            ClaimRequest::STATUS_RESUBMITTED,
            ClaimRequest::STATUS_APPROVED,
            ClaimRequest::STATUS_QUEUED_FOR_PAYROLL,
            ClaimRequest::STATUS_REIMBURSED,
            ClaimRequest::STATUS_SETTLED,
        ];

        if ($receiptNumber !== null && trim($receiptNumber) !== '') {
            $receiptMatch = ClaimLine::query()
                ->where('claim_type_id', $claimTypeId)
                ->where('receipt_number', trim($receiptNumber))
                ->whereHas('request', fn ($query) => $query
                    ->where('employee_id', $employeeId)
                    ->whereIn('status', $activeStatuses))
                ->with('request')
                ->latest('id')
                ->first();

            if ($receiptMatch !== null) {
                $risks[] = [
                    'code' => 'same_receipt_number',
                    'message' => 'A claim line for this employee, claim type, and receipt number already exists.',
                    'matching_claim_line_id' => (int) $receiptMatch->getKey(),
                    'matching_claim_request_id' => $receiptMatch->request?->getKey(),
                ];
            }
        }

        $sameShapeMatch = ClaimLine::query()
            ->where('claim_type_id', $claimTypeId)
            ->whereDate('incurred_on', $incurredOn->format('Y-m-d'))
            ->where('requested_amount', $requestedAmount)
            ->when($providerName !== null && trim($providerName) !== '', fn ($query) => $query->where('provider_name', trim($providerName)))
            ->whereHas('request', fn ($query) => $query
                ->where('employee_id', $employeeId)
                ->whereIn('status', $activeStatuses))
            ->with('request')
            ->latest('id')
            ->first();

        if ($sameShapeMatch !== null) {
            $risks[] = [
                'code' => 'same_type_date_amount_provider',
                'message' => 'A similar claim line for this employee, claim type, date, amount, and provider already exists.',
                'matching_claim_line_id' => (int) $sameShapeMatch->getKey(),
                'matching_claim_request_id' => $sameShapeMatch->request?->getKey(),
            ];
        }

        return $risks;
    }
}
