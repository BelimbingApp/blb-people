<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\People\Claim\Models\ClaimLine;
use App\Modules\People\Claim\Models\ClaimPolicy;
use App\Modules\People\Claim\Models\ClaimPolicyBand;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Models\ClaimType;
use DateTimeImmutable;

class ClaimPolicyEvaluationService
{
    /**
     * @return array{blocking: list<string>, snapshot: array<string, mixed>}
     *
     * @param  list<int>  $combinedClaimTypeIds
     */
    public function evaluateBeforeSubmission(
        int $employeeId,
        ClaimType $claimType,
        ?ClaimPolicy $policy,
        DateTimeImmutable $incurredOn,
        float $requestedAmount,
        int $attachmentCount,
        ?string $providerName,
        array $combinedClaimTypeIds = [],
    ): array {
        $blocking = [];
        $band = $this->matchingBand($policy, $requestedAmount);
        $receiptRequired = $this->receiptRequired($claimType, $policy, $requestedAmount);
        $providerRequired = $this->providerRequired($claimType, $policy);
        $capClaimTypeIds = $combinedClaimTypeIds === [] ? [(int) $claimType->getKey()] : $combinedClaimTypeIds;

        if ($receiptRequired && $attachmentCount < 1) {
            $blocking[] = sprintf('%s requires a receipt attachment.', $claimType->name);
        }

        if ($providerRequired && trim((string) $providerName) === '') {
            $blocking[] = sprintf('%s requires a provider name.', $claimType->name);
        }

        if ($band !== null) {
            $perClaimLimit = $this->numericOrNull($band->per_claim_limit);
            if ($perClaimLimit !== null && $requestedAmount > $perClaimLimit) {
                $blocking[] = sprintf('%s has a per-claim limit of %.2f.', $claimType->name, $perClaimLimit);
            }

            $perMonthLimit = $this->numericOrNull($band->per_month_limit);
            if ($perMonthLimit !== null) {
                $usedThisMonth = $this->usedAmountForPeriod($employeeId, $capClaimTypeIds, $incurredOn, 'month');
                if ($usedThisMonth + $requestedAmount > $perMonthLimit) {
                    $blocking[] = sprintf('%s has a monthly limit of %.2f; %.2f is already used or pending.', $claimType->name, $perMonthLimit, $usedThisMonth);
                }
            }

            $perYearLimit = $this->numericOrNull($band->per_year_limit);
            if ($perYearLimit !== null) {
                $usedThisYear = $this->usedAmountForPeriod($employeeId, $capClaimTypeIds, $incurredOn, 'year');
                if ($usedThisYear + $requestedAmount > $perYearLimit) {
                    $blocking[] = sprintf('%s has a yearly limit of %.2f; %.2f is already used or pending.', $claimType->name, $perYearLimit, $usedThisYear);
                }
            }
        }

        return [
            'blocking' => $blocking,
            'snapshot' => [
                'claim_policy_id' => $policy?->getKey(),
                'claim_policy_code' => $policy?->code,
                'claim_policy_version' => $policy?->version,
                'item_mode' => $policy?->item_mode,
                'matched_band_id' => $band?->getKey(),
                'threshold_value' => $band?->threshold_value,
                'per_claim_limit' => $band?->per_claim_limit,
                'per_month_limit' => $band?->per_month_limit,
                'per_year_limit' => $band?->per_year_limit,
                'receipt_required' => $receiptRequired,
                'provider_required' => $providerRequired,
                'combined_claim_type_ids' => $combinedClaimTypeIds,
            ],
        ];
    }

    private function matchingBand(?ClaimPolicy $policy, float $requestedAmount): ?ClaimPolicyBand
    {
        if ($policy === null) {
            return null;
        }

        $policy->loadMissing('bands');

        return $policy->bands->first(function (ClaimPolicyBand $band) use ($requestedAmount): bool {
            $threshold = $this->numericOrNull($band->threshold_value);
            if ($threshold === null) {
                return true;
            }

            return match ($band->logical_operator) {
                '<' => $requestedAmount < $threshold,
                '>=' => $requestedAmount >= $threshold,
                '>' => $requestedAmount > $threshold,
                '=' => $requestedAmount === $threshold,
                default => $requestedAmount <= $threshold,
            };
        });
    }

    private function receiptRequired(ClaimType $claimType, ?ClaimPolicy $policy, float $requestedAmount): bool
    {
        if ($claimType->receipt_requirement === ClaimType::RECEIPT_ALWAYS) {
            return true;
        }

        if ($claimType->receipt_requirement === ClaimType::RECEIPT_NEVER) {
            return false;
        }

        $receiptRules = is_array($policy?->receipt_rules) ? $policy->receipt_rules : [];
        $threshold = $this->numericOrNull($receiptRules['required_above_amount'] ?? $receiptRules['threshold'] ?? null);

        return $threshold === null || $requestedAmount > $threshold;
    }

    private function providerRequired(ClaimType $claimType, ?ClaimPolicy $policy): bool
    {
        if ((bool) $claimType->provider_required) {
            return true;
        }

        $providerRules = is_array($policy?->provider_rules) ? $policy->provider_rules : [];

        return (bool) ($providerRules['required'] ?? false);
    }

    /**
     * @param  list<int>  $claimTypeIds
     */
    private function usedAmountForPeriod(int $employeeId, array $claimTypeIds, DateTimeImmutable $incurredOn, string $period): float
    {
        $query = ClaimLine::query()
            ->whereIn('claim_type_id', $claimTypeIds)
            ->whereHas('request', fn ($query) => $query
                ->where('employee_id', $employeeId)
                ->whereNotIn('status', [
                    ClaimRequest::STATUS_REJECTED,
                    ClaimRequest::STATUS_CANCELLED,
                    ClaimRequest::STATUS_WITHDRAWN,
                ]));

        if ($period === 'month') {
            $query->whereYear('incurred_on', (int) $incurredOn->format('Y'))
                ->whereMonth('incurred_on', (int) $incurredOn->format('m'));
        } else {
            $query->whereYear('incurred_on', (int) $incurredOn->format('Y'));
        }

        return (float) $query->sum('requested_amount');
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
