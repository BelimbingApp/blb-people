<?php

namespace App\Modules\People\Leave\CountryPacks\Malaysia;

use App\Modules\People\Leave\Contracts\ProvidesStatutoryLeaveTypes;
use App\Modules\People\Leave\Data\StatutoryLeaveTypeDefinition;
use App\Modules\People\Leave\Models\LeaveType;

class MalaysiaStatutoryLeaveTypes implements ProvidesStatutoryLeaveTypes
{
    public const CODE_ANNUAL = 'annual_leave';

    public const CODE_SICK = 'sick_leave';

    public const CODE_HOSPITALIZATION = 'hospitalization_leave';

    public const CODE_MATERNITY = 'maternity_leave';

    public const CODE_PATERNITY = 'paternity_leave';

    public const CODE_UNPAID = 'unpaid_leave';

    public const CODE_UNAUTHORIZED_ABSENCE = 'unauthorized_absence';

    public function statutoryLeaveTypes(): array
    {
        return [
            new StatutoryLeaveTypeDefinition(
                code: self::CODE_ANNUAL,
                name: 'Annual Leave',
                paid: true,
                defaultUnit: LeaveType::UNIT_DAY,
                metadata: ['act_reference' => 'Employment Act 1955 s.60E'],
            ),
            new StatutoryLeaveTypeDefinition(
                code: self::CODE_SICK,
                name: 'Sick Leave',
                paid: true,
                defaultUnit: LeaveType::UNIT_DAY,
                compulsoryAttachment: true,
                metadata: ['act_reference' => 'Employment Act 1955 s.60F'],
            ),
            new StatutoryLeaveTypeDefinition(
                code: self::CODE_HOSPITALIZATION,
                name: 'Hospitalization Leave',
                paid: true,
                defaultUnit: LeaveType::UNIT_DAY,
                compulsoryAttachment: true,
                metadata: [
                    'act_reference' => 'Employment Act 1955 s.60F(1)(aa)',
                    'aggregate_with' => self::CODE_SICK,
                    'aggregate_cap_days' => 60,
                ],
            ),
            new StatutoryLeaveTypeDefinition(
                code: self::CODE_MATERNITY,
                name: 'Maternity Leave',
                paid: true,
                defaultUnit: LeaveType::UNIT_DAY,
                compulsoryAttachment: true,
                eligibilityPredicate: ['gender' => 'female'],
                metadata: ['act_reference' => 'Employment Act 1955 s.37 (as amended by Act A1651)'],
            ),
            new StatutoryLeaveTypeDefinition(
                code: self::CODE_PATERNITY,
                name: 'Paternity Leave',
                paid: true,
                defaultUnit: LeaveType::UNIT_DAY,
                compulsoryAttachment: true,
                eligibilityPredicate: ['gender' => 'male', 'marital_status' => 'married'],
                metadata: ['act_reference' => 'Employment Act 1955 s.60FA (as amended by Act A1651)'],
            ),
            new StatutoryLeaveTypeDefinition(
                code: self::CODE_UNPAID,
                name: 'Unpaid Leave',
                paid: false,
                defaultUnit: LeaveType::UNIT_DAY,
                interactsWithPayroll: true,
                payrollPayItemCode: LeaveType::PAYROLL_CODE_UNPAID_LEAVE,
            ),
            new StatutoryLeaveTypeDefinition(
                code: self::CODE_UNAUTHORIZED_ABSENCE,
                name: 'Unauthorized Absence',
                paid: false,
                defaultUnit: LeaveType::UNIT_DAY,
                interactsWithPayroll: true,
                payrollPayItemCode: LeaveType::PAYROLL_CODE_UNPAID_LEAVE,
                auditTag: 'discipline',
            ),
        ];
    }
}
