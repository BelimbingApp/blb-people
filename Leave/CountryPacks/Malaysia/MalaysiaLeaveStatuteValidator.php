<?php

namespace App\Modules\People\Leave\CountryPacks\Malaysia;

use App\Modules\People\Leave\Contracts\ValidatesLeaveAgainstStatute;
use App\Modules\People\Leave\Data\LeaveValidationIssue;
use App\Modules\People\Leave\Data\StatutoryEntitlementPolicy;
use App\Modules\People\Leave\Models\LeaveEntitlementPolicy;
use App\Modules\People\Leave\Models\LeaveEntitlementPolicyBand;
use App\Modules\People\Leave\Models\LeaveType;

class MalaysiaLeaveStatuteValidator implements ValidatesLeaveAgainstStatute
{
    public function __construct(
        private readonly MalaysiaStatutoryEntitlementPolicies $statutoryPolicies,
    ) {}

    public function validateEntitlementPolicy(LeaveEntitlementPolicy $policy): array
    {
        $leaveType = $policy->leaveType;

        if (! $leaveType instanceof LeaveType) {
            return [];
        }

        $floor = $this->floorForLeaveTypeCode($leaveType->code);

        if ($floor === null) {
            return [];
        }

        $issues = [];
        $configuredBands = $policy->relationLoaded('bands')
            ? $policy->getRelation('bands')->sortBy('min_years_of_service')
            : $policy->bands()->orderBy('min_years_of_service')->get();

        foreach ($floor->bands as $statutoryBand) {
            $configured = $this->configuredEntitlementAt($configuredBands, $statutoryBand->minYearsOfService);

            if ($configured === null) {
                $issues[] = new LeaveValidationIssue(
                    code: 'missing_band',
                    message: sprintf(
                        '%s: no configured band covers %.2f years of service (statutory floor: %.2f days).',
                        $leaveType->name,
                        $statutoryBand->minYearsOfService,
                        $statutoryBand->entitlementDays,
                    ),
                    explanation: [
                        'leave_type' => $leaveType->code,
                        'service_band_min_years' => $statutoryBand->minYearsOfService,
                        'statutory_floor_days' => $statutoryBand->entitlementDays,
                    ],
                );

                continue;
            }

            if ($configured < $statutoryBand->entitlementDays) {
                $issues[] = new LeaveValidationIssue(
                    code: 'below_statutory_floor',
                    message: sprintf(
                        '%s: configured %.2f days at %.2f years of service is below the statutory floor of %.2f days.',
                        $leaveType->name,
                        $configured,
                        $statutoryBand->minYearsOfService,
                        $statutoryBand->entitlementDays,
                    ),
                    explanation: [
                        'leave_type' => $leaveType->code,
                        'service_band_min_years' => $statutoryBand->minYearsOfService,
                        'configured_days' => $configured,
                        'statutory_floor_days' => $statutoryBand->entitlementDays,
                        'act_reference' => $floor->metadata['act_reference'] ?? null,
                    ],
                );
            }
        }

        return $issues;
    }

    private function floorForLeaveTypeCode(string $code): ?StatutoryEntitlementPolicy
    {
        foreach ($this->statutoryPolicies->statutoryEntitlementPolicies() as $policy) {
            if ($policy->leaveTypeCode === $code) {
                return $policy;
            }
        }

        return null;
    }

    /** @param iterable<LeaveEntitlementPolicyBand> $configuredBands */
    private function configuredEntitlementAt(iterable $configuredBands, float $yearsOfService): ?float
    {
        $match = null;
        foreach ($configuredBands as $band) {
            $min = (float) $band->min_years_of_service;
            $max = $band->max_years_of_service === null ? null : (float) $band->max_years_of_service;

            if ($yearsOfService >= $min && ($max === null || $yearsOfService < $max)) {
                $match = (float) $band->entitlement_days;
            }
        }

        return $match;
    }
}
