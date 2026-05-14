<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Claim\Models\ClaimLine;
use App\Modules\People\Claim\Models\ClaimPolicy;
use App\Modules\People\Claim\Models\ClaimPolicyBand;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Models\ClaimType;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

class ClaimPolicyEvaluationService
{
    /**
     * @param  list<int>  $combinedClaimTypeIds
     * @return array{blocking: list<string>, snapshot: array<string, mixed>}
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
        ?Employee $employee = null,
    ): array {
        $blocking = [];
        $yearsOfService = $this->yearsOfService($employee, $incurredOn);
        $band = $this->matchingBand($policy, $requestedAmount, $yearsOfService);
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
                $usedThisMonth = $this->usedAmountForPeriod($employeeId, $capClaimTypeIds, $incurredOn, 'month', $policy);
                if ($usedThisMonth + $requestedAmount > $perMonthLimit) {
                    $blocking[] = sprintf('%s has a monthly limit of %.2f; %.2f is already used or pending.', $claimType->name, $perMonthLimit, $usedThisMonth);
                }
            }

            $perYearLimit = $this->numericOrNull($band->per_year_limit);
            if ($perYearLimit !== null) {
                $usedThisYear = $this->usedAmountForPeriod($employeeId, $capClaimTypeIds, $incurredOn, 'year', $policy);
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

    /**
     * Re-evaluate caps for a claim line at approval time, using the line's snapshot policy id
     * and excluding the line's own request from prior-usage totals so it does not block itself.
     *
     *
     * @param  list<int>  $combinedClaimTypeIds
     * @return list<string> blocking reasons (empty when approval is safe)
     */
    public function evaluateAtApproval(
        ClaimLine $line,
        float $approvingAmount,
        array $combinedClaimTypeIds = [],
    ): array {
        $line->loadMissing(['policy', 'type', 'request.employee']);
        $policy = $line->policy;
        $claimType = $line->type;
        $request = $line->request;
        $employee = $request->employee ?? null;

        $blocking = [];
        $incurredOn = $line->incurred_on instanceof DateTimeImmutable
            ? $line->incurred_on
            : new DateTimeImmutable((string) $line->incurred_on);
        $yearsOfService = $this->yearsOfService($employee, $incurredOn);
        $band = $this->matchingBand($policy, $approvingAmount, $yearsOfService);

        if ($band === null) {
            return $blocking;
        }

        $perClaimLimit = $this->numericOrNull($band->per_claim_limit);
        if ($perClaimLimit !== null && $approvingAmount > $perClaimLimit) {
            $blocking[] = sprintf('%s has a per-claim limit of %.2f.', $claimType?->name ?? 'claim', $perClaimLimit);
        }

        $capClaimTypeIds = $combinedClaimTypeIds === [] ? [(int) $line->claim_type_id] : $combinedClaimTypeIds;

        $perMonthLimit = $this->numericOrNull($band->per_month_limit);
        if ($perMonthLimit !== null) {
            $used = $this->usedAmountForPeriod(
                (int) $request->employee_id,
                $capClaimTypeIds,
                $incurredOn,
                'month',
                $policy,
                excludeRequestId: (int) $request->getKey(),
            );
            if ($used + $approvingAmount > $perMonthLimit) {
                $blocking[] = sprintf('%s has a monthly limit of %.2f; %.2f is already used or pending.', $claimType?->name ?? 'claim', $perMonthLimit, $used);
            }
        }

        $perYearLimit = $this->numericOrNull($band->per_year_limit);
        if ($perYearLimit !== null) {
            $used = $this->usedAmountForPeriod(
                (int) $request->employee_id,
                $capClaimTypeIds,
                $incurredOn,
                'year',
                $policy,
                excludeRequestId: (int) $request->getKey(),
            );
            if ($used + $approvingAmount > $perYearLimit) {
                $blocking[] = sprintf('%s has a yearly limit of %.2f; %.2f is already used or pending.', $claimType?->name ?? 'claim', $perYearLimit, $used);
            }
        }

        return $blocking;
    }

    /**
     * Pick the first band whose threshold matches the comparison value. The metric depends on the
     * policy's item_mode: service_year compares against the employee's years of service so a
     * tenure-banded entitlement table (e.g. 0-2y → 14 days, 2-5y → 16 days) resolves to the right
     * cap row; everything else compares against the requested/approving amount.
     */
    private function matchingBand(?ClaimPolicy $policy, float $requestedAmount, ?float $yearsOfService = null): ?ClaimPolicyBand
    {
        if ($policy === null) {
            return null;
        }

        $policy->loadMissing('bands');

        $comparisonValue = $policy->item_mode === ClaimPolicy::MODE_SERVICE_YEAR
            ? ($yearsOfService ?? 0.0)
            : $requestedAmount;

        return $policy->bands->first(function (ClaimPolicyBand $band) use ($comparisonValue): bool {
            $threshold = $this->numericOrNull($band->threshold_value);
            if ($threshold === null) {
                return true;
            }

            return match ($band->logical_operator) {
                '<' => $comparisonValue < $threshold,
                '>=' => $comparisonValue >= $threshold,
                '>' => $comparisonValue > $threshold,
                '=' => abs($comparisonValue - $threshold) < 0.005,
                default => $comparisonValue <= $threshold,
            };
        });
    }

    private function yearsOfService(?Employee $employee, DateTimeImmutable $asOf): ?float
    {
        if ($employee === null || $employee->employment_start === null) {
            return null;
        }

        $start = CarbonImmutable::parse($employee->employment_start);
        $end = CarbonImmutable::instance(\DateTime::createFromImmutable($asOf));

        if ($end->lessThan($start)) {
            return 0.0;
        }

        return $start->diffInDays($end) / 365.25;
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
     * Sum cap utilization for the employee's prior claim lines in the period.
     *
     * Finalized statuses (approved → reimbursed/settled) consume approved_amount, so a $500
     * request approved at $200 only spends $200 of cap. Pending statuses encumber requested_amount
     * only when the policy under evaluation opts in via {@see ClaimPolicy::encumber_pending}.
     *
     * @param  list<int>  $claimTypeIds
     */
    private function usedAmountForPeriod(
        int $employeeId,
        array $claimTypeIds,
        DateTimeImmutable $incurredOn,
        string $period,
        ?ClaimPolicy $policy,
        ?int $excludeRequestId = null,
    ): float {
        $base = fn () => ClaimLine::query()
            ->whereIn('claim_type_id', $claimTypeIds)
            ->when($excludeRequestId !== null, fn ($q) => $q->where('claim_request_id', '!=', $excludeRequestId))
            ->whereHas('request', fn ($q) => $q->where('employee_id', $employeeId));

        $applyPeriod = function ($query) use ($period, $incurredOn) {
            if ($period === 'month') {
                $query->whereYear('incurred_on', (int) $incurredOn->format('Y'))
                    ->whereMonth('incurred_on', (int) $incurredOn->format('m'));
            } else {
                $query->whereYear('incurred_on', (int) $incurredOn->format('Y'));
            }

            return $query;
        };

        $finalizedQuery = $applyPeriod($base())
            ->whereHas('request', fn ($q) => $q->whereIn('status', [
                ClaimRequest::STATUS_APPROVED,
                ClaimRequest::STATUS_QUEUED_FOR_PAYROLL,
                ClaimRequest::STATUS_REIMBURSED,
                ClaimRequest::STATUS_SETTLED,
            ]));

        $total = (float) $finalizedQuery->sum('approved_amount');

        if ($policy === null || (bool) $policy->encumber_pending) {
            $pendingQuery = $applyPeriod($base())
                ->whereHas('request', fn ($q) => $q->whereIn('status', [
                    ClaimRequest::STATUS_SUBMITTED,
                    ClaimRequest::STATUS_NEEDS_MORE_INFO,
                    ClaimRequest::STATUS_RESUBMITTED,
                ]));

            $total += (float) $pendingQuery->sum('requested_amount');
        }

        return $total;
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
