<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\People\Claim\Models\ClaimPolicy;

/**
 * Lints a {@see ClaimPolicy} and returns linter-style findings.
 *
 * Output shape mirrors AttendancePolicyValidationService so a future shared Policy Studio surface
 * can render Attendance and Claim findings identically:
 *
 *   { status: 'ok'|'warning'|'error',
 *     policy: { id, code, name, version, status },
 *     summary: { errors, warnings, info },
 *     findings: [ { severity, code, message, path } ] }
 *
 * The validator is read-only — it surfaces inconsistencies (missing effective_from, inverted
 * date range, empty bands, unknown cohort keys, etc.) without mutating the policy. UI / CLI /
 * pre-save hooks call this and decide whether to block.
 */
class ClaimPolicyValidationService
{
    /** @var list<string> */
    private const COHORT_ALLOWED_KEYS = [
        'employee_type',
        'department_id',
        'supervisor_id',
        'status',
    ];

    /** @return array<string, mixed> */
    public function validate(ClaimPolicy $policy): array
    {
        $policy->loadMissing('bands');

        $findings = [
            ...$this->validateIdentity($policy),
            ...$this->validateBands($policy),
            ...$this->validateCohortPredicate($policy),
            ...$this->validateReceiptRules($policy),
            ...$this->validateEncumbranceConsistency($policy),
        ];

        return [
            'status' => $this->statusFor($findings),
            'policy' => [
                'id' => $policy->id,
                'company_id' => $policy->company_id,
                'code' => $policy->code,
                'name' => $policy->name,
                'item_mode' => $policy->item_mode,
                'version' => $policy->version,
                'status' => $policy->status,
            ],
            'summary' => [
                'errors' => $this->countSeverity($findings, 'error'),
                'warnings' => $this->countSeverity($findings, 'warning'),
                'info' => $this->countSeverity($findings, 'info'),
            ],
            'findings' => $findings,
        ];
    }

    /** @return list<array<string, string>> */
    private function validateIdentity(ClaimPolicy $policy): array
    {
        $findings = [];

        if ($policy->effective_from === null) {
            $findings[] = $this->finding('error', 'policy_effective_from_missing', 'Policy must have an effective_from date.', 'effective_from');
        }

        if ($policy->effective_to !== null && $policy->effective_from !== null && $policy->effective_to->lt($policy->effective_from)) {
            $findings[] = $this->finding('error', 'policy_effective_range_invalid', 'Policy effective_to must not be earlier than effective_from.', 'effective_to');
        }

        if ($policy->status !== 'active') {
            $findings[] = $this->finding('warning', 'policy_not_active', 'Policy is not active; assignments referencing it will not match new submissions.', 'status');
        }

        return $findings;
    }

    /** @return list<array<string, string>> */
    private function validateBands(ClaimPolicy $policy): array
    {
        $findings = [];
        $bands = $policy->bands;

        if ($bands->isEmpty()) {
            $findings[] = $this->finding('error', 'policy_bands_missing', 'Policy must declare at least one band.', 'bands');

            return $findings;
        }

        $hasCatchAll = false;
        $thresholds = [];
        foreach ($bands as $index => $band) {
            $path = "bands.{$index}";
            $threshold = $this->numericOrNull($band->threshold_value);

            if ($threshold === null) {
                $hasCatchAll = true;
            } else {
                $thresholds[] = $threshold;
            }

            if (! in_array($band->logical_operator, ['<', '<=', '>', '>=', '='], true)) {
                $findings[] = $this->finding('error', 'band_operator_invalid', 'Band logical_operator must be one of <, <=, >, >=, =.', "{$path}.logical_operator");
            }

            foreach (['per_claim_limit', 'per_month_limit', 'per_year_limit', 'per_day_unit_limit'] as $capKey) {
                $value = $this->numericOrNull($band->{$capKey});
                if ($value !== null && $value < 0) {
                    $findings[] = $this->finding('error', 'band_cap_negative', sprintf('Band %s cannot be negative.', $capKey), "{$path}.{$capKey}");
                }
            }

            $perClaim = $this->numericOrNull($band->per_claim_limit);
            $perMonth = $this->numericOrNull($band->per_month_limit);
            $perYear = $this->numericOrNull($band->per_year_limit);

            if ($perClaim !== null && $perMonth !== null && $perClaim > $perMonth) {
                $findings[] = $this->finding('warning', 'band_per_claim_exceeds_month', 'Per-claim limit exceeds per-month limit — a single claim could blow the monthly cap.', "{$path}.per_claim_limit");
            }
            if ($perMonth !== null && $perYear !== null && $perMonth > $perYear) {
                $findings[] = $this->finding('warning', 'band_per_month_exceeds_year', 'Per-month limit exceeds per-year limit.', "{$path}.per_month_limit");
            }
        }

        if (! $hasCatchAll) {
            $findings[] = $this->finding('warning', 'policy_band_no_catchall', 'No band has a null threshold; requests above the highest band may not match.', 'bands');
        }

        if ($policy->item_mode === ClaimPolicy::MODE_SERVICE_YEAR) {
            $previous = null;
            foreach ($thresholds as $idx => $current) {
                if ($previous !== null && $current < $previous) {
                    $findings[] = $this->finding('warning', 'service_year_thresholds_not_monotonic', 'Service-year band thresholds should be monotonically increasing for predictable matching.', "bands.{$idx}.threshold_value");
                    break;
                }
                $previous = $current;
            }
        }

        return $findings;
    }

    /** @return list<array<string, string>> */
    private function validateCohortPredicate(ClaimPolicy $policy): array
    {
        $predicate = $policy->cohort_predicate;
        if (! is_array($predicate) || $predicate === []) {
            return [];
        }

        $findings = [];
        foreach ($predicate as $key => $_value) {
            if (! in_array($key, self::COHORT_ALLOWED_KEYS, true)) {
                $findings[] = $this->finding(
                    'error',
                    'cohort_predicate_key_invalid',
                    sprintf('Cohort predicate key [%s] is not allowed. Allowed keys: %s.', $key, implode(', ', self::COHORT_ALLOWED_KEYS)),
                    "cohort_predicate.{$key}",
                );
            }
        }

        return $findings;
    }

    /** @return list<array<string, string>> */
    private function validateReceiptRules(ClaimPolicy $policy): array
    {
        $rules = $policy->receipt_rules;
        if (! is_array($rules) || $rules === []) {
            return [];
        }

        $findings = [];
        foreach (['threshold_amount', 'threshold', 'required_above_amount'] as $key) {
            if (array_key_exists($key, $rules) && ! is_numeric($rules[$key])) {
                $findings[] = $this->finding('error', 'receipt_rules_threshold_invalid', sprintf('Receipt rule [%s] must be numeric.', $key), "receipt_rules.{$key}");
            }
        }

        return $findings;
    }

    /** @return list<array<string, string>> */
    private function validateEncumbranceConsistency(ClaimPolicy $policy): array
    {
        $findings = [];

        if (! (bool) $policy->encumber_pending) {
            $findings[] = $this->finding('info', 'policy_no_pending_encumbrance', 'Pending claims are not encumbered. Employees may submit further claims even when an existing pending claim would exceed the cap if approved.', 'encumber_pending');
        }

        return $findings;
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** @return array<string, string> */
    private function finding(string $severity, string $code, string $message, string $path): array
    {
        return compact('severity', 'code', 'message', 'path');
    }

    /** @param  list<array<string, string>>  $findings */
    private function statusFor(array $findings): string
    {
        if ($this->countSeverity($findings, 'error') > 0) {
            return 'error';
        }

        if ($this->countSeverity($findings, 'warning') > 0) {
            return 'warning';
        }

        return 'ok';
    }

    /** @param  list<array<string, string>>  $findings */
    private function countSeverity(array $findings, string $severity): int
    {
        return count(array_filter($findings, fn (array $finding): bool => $finding['severity'] === $severity));
    }
}
