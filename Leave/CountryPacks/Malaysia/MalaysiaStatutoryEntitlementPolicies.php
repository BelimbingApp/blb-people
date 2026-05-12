<?php

namespace App\Modules\People\Leave\CountryPacks\Malaysia;

use App\Modules\People\Leave\Contracts\ProvidesStatutoryEntitlementPolicies;
use App\Modules\People\Leave\Data\StatutoryEntitlementBand;
use App\Modules\People\Leave\Data\StatutoryEntitlementPolicy;
use App\Modules\People\Leave\Models\LeaveEntitlementPolicy;

class MalaysiaStatutoryEntitlementPolicies implements ProvidesStatutoryEntitlementPolicies
{
    public function statutoryEntitlementPolicies(): array
    {
        return [
            new StatutoryEntitlementPolicy(
                leaveTypeCode: MalaysiaStatutoryLeaveTypes::CODE_ANNUAL,
                code: 'my_statutory_annual_leave_floor',
                name: 'Annual Leave (Employment Act minimum)',
                accrualMethod: LeaveEntitlementPolicy::ACCRUAL_ANNUAL_LUMP_NO_PRORATE,
                bands: [
                    new StatutoryEntitlementBand(0.0, 2.0, 8.0),
                    new StatutoryEntitlementBand(2.0, 5.0, 12.0),
                    new StatutoryEntitlementBand(5.0, null, 16.0),
                ],
                metadata: ['act_reference' => 's.60E(1)(a)-(c)'],
            ),
            new StatutoryEntitlementPolicy(
                leaveTypeCode: MalaysiaStatutoryLeaveTypes::CODE_SICK,
                code: 'my_statutory_sick_leave_floor',
                name: 'Sick Leave (Employment Act minimum)',
                accrualMethod: LeaveEntitlementPolicy::ACCRUAL_ANNUAL_LUMP_NO_PRORATE,
                bands: [
                    new StatutoryEntitlementBand(0.0, 2.0, 14.0),
                    new StatutoryEntitlementBand(2.0, 5.0, 18.0),
                    new StatutoryEntitlementBand(5.0, null, 22.0),
                ],
                metadata: ['act_reference' => 's.60F(1)(a)-(c)'],
            ),
            new StatutoryEntitlementPolicy(
                leaveTypeCode: MalaysiaStatutoryLeaveTypes::CODE_HOSPITALIZATION,
                code: 'my_statutory_hospitalization_floor',
                name: 'Hospitalization Leave (Employment Act minimum)',
                accrualMethod: LeaveEntitlementPolicy::ACCRUAL_ANNUAL_LUMP_NO_PRORATE,
                bands: [
                    new StatutoryEntitlementBand(0.0, null, 60.0),
                ],
                aggregateCapDays: 60.0,
                metadata: [
                    'act_reference' => 's.60F(1)(aa)',
                    'aggregate_with' => MalaysiaStatutoryLeaveTypes::CODE_SICK,
                ],
            ),
            new StatutoryEntitlementPolicy(
                leaveTypeCode: MalaysiaStatutoryLeaveTypes::CODE_MATERNITY,
                code: 'my_statutory_maternity_floor',
                name: 'Maternity Leave (Employment Act minimum, 98 days)',
                accrualMethod: LeaveEntitlementPolicy::ACCRUAL_ANNUAL_LUMP_NO_PRORATE,
                bands: [
                    new StatutoryEntitlementBand(0.0, null, 98.0),
                ],
                eligibilityPredicate: ['gender' => 'female'],
                metadata: ['act_reference' => 's.37 (Act A1651, effective 2023-01-01)'],
            ),
            new StatutoryEntitlementPolicy(
                leaveTypeCode: MalaysiaStatutoryLeaveTypes::CODE_PATERNITY,
                code: 'my_statutory_paternity_floor',
                name: 'Paternity Leave (Employment Act minimum, 7 days)',
                accrualMethod: LeaveEntitlementPolicy::ACCRUAL_ANNUAL_LUMP_NO_PRORATE,
                bands: [
                    new StatutoryEntitlementBand(0.0, null, 7.0),
                ],
                eligibilityPredicate: ['gender' => 'male', 'marital_status' => 'married'],
                metadata: ['act_reference' => 's.60FA (Act A1651, effective 2023-01-01)'],
            ),
        ];
    }
}
