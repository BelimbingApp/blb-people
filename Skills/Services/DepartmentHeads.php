<?php

namespace App\Domains\People\Skills\Services;

use App\Core\Company\Models\Department;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;

/**
 * Who heads an employee's department, as a platform user.
 *
 * One rule shared by every People lane that addresses a HOD: the employee's
 * department, that department's head, and the user account of that head in
 * the same company. Any step missing yields null, and the caller decides
 * whether that is "skip" or "refuse".
 */
final class DepartmentHeads
{
    public function headUserOf(int $companyEntityId, int $employeeEntityId): ?int
    {
        $departmentId = Employee::query()
            ->where('company_id', $companyEntityId)
            ->whereKey($employeeEntityId)
            ->value('department_id');

        if ($departmentId === null) {
            return null;
        }

        $headId = Department::query()
            ->where('company_id', $companyEntityId)
            ->whereKey($departmentId)
            ->value('head_id');

        if ($headId === null) {
            return null;
        }

        $userId = User::query()
            ->where('company_id', $companyEntityId)
            ->where('employee_id', $headId)
            ->value('id');

        return $userId === null ? null : (int) $userId;
    }
}
